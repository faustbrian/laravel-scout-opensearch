<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Engines;

use Cline\LaravelScout\OpenSearch\Contracts\ScoutModel;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use OpenSearch\Client;
use stdClass;

use function array_filter;
use function array_flip;
use function array_merge;
use function call_user_func;
use function class_uses_recursive;
use function collect;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function method_exists;
use function sprintf;
use function str_starts_with;
use function throw_if;
use function throw_unless;

/**
 * @mixin Client
 */
final class OpenSearchEngine extends Engine
{
    /**
     * Create a new engine instance.
     */
    public function __construct(
        private readonly Client $client,
        private readonly bool $softDelete = false,
    ) {}

    /**
     * Dynamically call the OpenSearch client instance.
     *
     * @param array<int, mixed> $parameters
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->client->{$method}(...$parameters);
    }

    /**
     * Update the given model in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, covariant Model> $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model $model First model for search index */
        $model = $models->first();
        $searchableModel = $this->searchableModel($model);

        if ($this->usesSoftDelete($searchableModel) && $this->softDelete) {
            $models->each(function (Model $model): void {
                $this->searchableModel($model)->pushSoftDeleteMetadata();
            });
        }

        $objects = $models->map(function (Model $model): array {
            $searchableModel = $this->searchableModel($model);
            $searchableData = $searchableModel->toSearchableArray();

            if (empty($searchableData)) {
                return [];
            }

            return array_merge($searchableData, $searchableModel->scoutMetadata(), [
                $searchableModel->getScoutKeyName() => $searchableModel->getScoutKey(),
            ]);
        })
            ->filter()
            ->values()
            ->all();

        if ($objects === []) {
            return;
        }

        $data = [];

        foreach ($objects as $object) {
            $data[] = [
                'index' => [
                    '_index' => $searchableModel->searchableAs(),
                    '_id' => $object[$searchableModel->getScoutKeyName()],
                ],
            ];
            $data[] = $object;
        }

        /** @var array{index: string, body: non-empty-list<array<string, mixed>>} $payload */
        $payload = [
            'index' => $searchableModel->searchableAs(),
            'body' => $data,
        ];

        $this->client->bulk($payload);
    }

    /**
     * Remove the given model from the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, covariant Model> $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $this->searchableModel($models->first());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($model->getScoutKeyName())
            : $models->map(fn (Model $model): int|string => $this->searchableModel($model)->getScoutKey());

        /** @var Collection<int, int|string> $keys */
        $data = $keys->map(static fn (int|string $object): array => [
            'delete' => [
                '_index' => $model->searchableAs(),
                '_id' => $object,
            ],
        ])->all();

        /** @var array{index: string, body: list<array<string, array<string, int|string>>>} $payload */
        $payload = [
            'index' => $model->searchableAs(),
            'body' => $data,
        ];

        $this->client->bulk($payload);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder<covariant Model> $builder
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'query' => $this->buildQuery($builder),
            'sort' => $this->buildSort($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder<covariant Model> $builder
     * @param int                      $perPage
     * @param int                      $page
     */
    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        return $this->performSearch($builder, array_filter([
            'query' => $this->buildQuery($builder),
            'sort' => $this->buildSort($builder),
            'size' => $perPage,
            'from' => $perPage * ($page - 1),
        ]));
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param null|array{hits: null|array<mixed>} $results
     *
     * @return Collection<int, int|string>
     */
    public function mapIds($results): Collection
    {
        if ($results === null) {
            return collect();
        }

        /** @var Collection<int, mixed> $hits */
        $hits = collect($results['hits']);

        return $this->hitIds($hits);
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Builder<covariant Model>            $builder
     * @param null|array{hits: null|array<mixed>} $results
     * @param Model                               $model
     *
     * @return Collection<int, Model>
     */
    public function map(Builder $builder, $results, $model): mixed
    {
        $searchableModel = $this->searchableModel($model);

        if ($results === null) {
            return $searchableModel->newCollection()->values();
        }

        if (!isset($results['hits'])) {
            return $searchableModel->newCollection()->values();
        }

        if ($results['hits'] === []) {
            return $searchableModel->newCollection()->values();
        }

        /** @var list<int|string> $objectIds */
        $objectIds = collect($results['hits'])->pluck('_id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $searchableModel->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn (Model $model): bool => in_array($this->searchableModel($model)->getScoutKey(), $objectIds, false))
            ->map(function (Model $model) use ($results, $objectIdPositions): Model {
                $searchableModel = $this->searchableModel($model);

                /** @var array<string, mixed> $result */
                $result = $results['hits'][$objectIdPositions[$searchableModel->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (!str_starts_with($key, '_')) {
                        continue;
                    }

                    $searchableModel->withScoutMetadata($key, $value);
                }

                return $model;
            })
            ->sortBy(fn (Model $model): int => $objectIdPositions[$this->searchableModel($model)->getScoutKey()])->values();
    }

    /**
     * Map the given results to instances of the given model via
     * a lazy collection.
     *
     * @param Builder<covariant Model>            $builder
     * @param null|array{hits: null|array<mixed>} $results
     * @param Model                               $model
     *
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        $searchableModel = $this->searchableModel($model);

        if ($results === null) {
            return LazyCollection::make($searchableModel->newCollection());
        }

        if (!isset($results['hits'])) {
            return LazyCollection::make($searchableModel->newCollection());
        }

        if ($results['hits'] === []) {
            return LazyCollection::make($searchableModel->newCollection());
        }

        /** @var list<int|string> $objectIds */
        $objectIds = collect($results['hits'])->pluck('_id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $searchableModel->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn (Model $model): bool => in_array($this->searchableModel($model)->getScoutKey(), $objectIds, false))
            ->map(function (Model $model) use ($results, $objectIdPositions): Model {
                $searchableModel = $this->searchableModel($model);

                /** @var array<string, mixed> $result */
                $result = $results['hits'][$objectIdPositions[$searchableModel->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (!str_starts_with($key, '_')) {
                        continue;
                    }

                    $searchableModel->withScoutMetadata($key, $value);
                }

                return $model;
            })
            ->sortBy(fn (Model $model): int => $objectIdPositions[$this->searchableModel($model)->getScoutKey()])->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     */
    public function getTotalCount($results): int
    {
        /** @var array{total?: array{value?: int}} $results */
        return $results['total']['value'] ?? 0;
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param Model $model
     */
    public function flush($model): void
    {
        $searchableModel = $this->searchableModel($model);

        $this->client->deleteByQuery([
            'index' => $searchableModel->searchableAs(),
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ]);
    }

    /**
     * Create a search index.
     *
     * @param string               $name
     * @param array<string, mixed> $options
     *
     * @return array{acknowledged: bool, shards_acknowledged: bool, index: string}
     *
     * @phpstan-return array<string, mixed>
     */
    public function createIndex($name, array $options = []): array
    {
        return $this->arrayResponse($this->client->indices()
            ->create([
                'index' => $name,
                'body' => $options,
            ]));
    }

    /**
     * Delete a search index.
     *
     * @param string $name
     *
     * @return array{acknowledged: bool}
     *
     * @phpstan-return array<string, mixed>
     */
    public function deleteIndex($name): array
    {
        return $this->arrayResponse($this->client->indices()
            ->delete([
                'index' => $name,
            ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder<covariant Model> $builder
     * @param array<string, mixed>     $options
     */
    private function performSearch(Builder $builder, array $options = []): mixed
    {
        $index = $this->searchableModel($builder->model)->searchableAs();
        $options = array_merge($builder->options, $options);

        if ($builder->callback instanceof Closure) {
            /** @var array<string, mixed> $result */
            $result = call_user_func($builder->callback, $this->client, $builder->query, $options);

            $hits = $result['hits'] ?? [];

            return is_array($hits) && Arr::isAssoc($hits) ? $hits : $result;
        }

        $result = $this->client->search([
            'index' => $index,
            'body' => $options,
        ]);

        return $result['hits'] ?? null;
    }

    /**
     * @param Builder<covariant Model> $builder
     *
     * @return array<string, array<string, array<mixed>>>
     */
    private function buildQuery(Builder $builder): array
    {
        $query = $builder->query;

        /** @var Collection<int, array{bool: array<string, mixed>}|array{query_string?: array{query: string}}|array{range: array<string, mixed>}|array{term: array<string, mixed>}> $must */
        $must = collect([
            [
                'query_string' => [
                    'query' => $query,
                ],
            ],
        ]);
        $must = $must->merge(collect($builder->wheres)
            ->map(fn (mixed $value, int|string $key): array => $this->parseWhereFilter($value, $key))
            ->values())->values();

        $must = $must->merge(collect($builder->whereIns)->map(static fn (mixed $values, int|string $key): array => [
            'terms' => [
                $key => $values,
            ],
        ])->values())->values();

        $mustNot = collect();

        $mustNot = $mustNot->merge(collect($builder->whereNotIns)->map(static fn (mixed $values, int|string $key): array => [
            'terms' => [
                $key => $values,
            ],
        ])->values())->values();

        return [
            'bool' => [
                'must' => $must->all(),
                'must_not' => $mustNot->all(),
            ],
        ];
    }

    /**
     * @param Builder<covariant Model> $builder
     *
     * @return array<array<array{order: mixed}>>
     */
    private function buildSort(Builder $builder): array
    {
        /** @var Collection<int, array{column: string, direction: mixed}> $orders */
        $orders = collect($builder->orders);

        return $orders->map(
            /**
             * @param array{column: string, direction: mixed} $order
             */
            static fn (array $order): array => [
                $order['column'] => [
                    'order' => $order['direction'],
                ],
            ],
        )->all();
    }

    /**
     * @param string $key
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseWhereFilter(mixed $value, int|string $key): array
    {
        $operator = '=';

        if (is_numeric($key) && is_array($value) && count($value) === 3) {
            /** @var array{field: string, operator: string, value: mixed} $value */
            $key = $value['field'];
            $operator = $value['operator'];
            $value = $value['value'];
        }

        if (!is_string($key)) {
            throw new InvalidArgumentException(sprintf('Unsupported key type [%s].', $key));
        }

        if ($value === null) {
            return [
                'bool' => [
                    $operator === '=' ? 'must_not' : 'must' => [
                        'exists' => [
                            'field' => $key,
                        ],
                    ],
                ],
            ];
        }

        return match ($operator) {
            '=' => $this->parseEqualFilter($key, $value),
            '!=' => $this->parseNotEqualFilter($key, $value),
            '<', '>', '<=', '>=' => $this->parseRangeFilter($key, $operator, $value),
            default => throw new InvalidArgumentException(sprintf('Unsupported operator [%s].', $operator)),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseEqualFilter(string $field, mixed $value = null): array
    {
        if ($value === null) {
            return [
                'exists' => [
                    'field' => $field,
                ],
            ];
        }

        return [
            'term' => [
                $field => $value,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseNotEqualFilter(string $field, mixed $value = null): array
    {
        return [
            'bool' => [
                'must_not' => $this->parseEqualFilter($field, $value),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseRangeFilter(string $field, string $operator, mixed $value = null): array
    {
        $operator = match ($operator) {
            '<' => 'lt',
            '>' => 'gt',
            '<=' => 'lte',
            '>=' => 'gte',
            default => throw new InvalidArgumentException(sprintf('Unsupported operator [%s].', $operator)),
        };

        return [
            'range' => [
                $field => [
                    $operator => $value,
                ],
            ],
        ];
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    private function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * @phpstan-assert Model&ScoutModel $model
     */
    private function assertScoutModel(Model $model): void
    {
        throw_if(!method_exists($model, 'searchableAs')
        || !method_exists($model, 'toSearchableArray')
        || !method_exists($model, 'scoutMetadata')
        || !method_exists($model, 'getScoutKey')
        || !method_exists($model, 'getScoutKeyName')
        || !method_exists($model, 'getScoutModelsByIds')
        || !method_exists($model, 'queryScoutModelsByIds')
        || !method_exists($model, 'withScoutMetadata')
        || !method_exists($model, 'pushSoftDeleteMetadata'), InvalidArgumentException::class, 'Model must use Laravel Scout Searchable.');
    }

    /**
     * @return Model&ScoutModel
     */
    private function searchableModel(Model $model): Model
    {
        $this->assertScoutModel($model);

        return $model;
    }

    /**
     * @param Collection<int, mixed> $hits
     *
     * @return Collection<int, int|string>
     */
    private function hitIds(Collection $hits): Collection
    {
        return $hits
            ->pluck('_id')
            ->map(static function (mixed $id): int|string {
                throw_if(!is_int($id) && !is_string($id), InvalidArgumentException::class, 'Expected OpenSearch hit id to be int|string.');

                return $id;
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayResponse(mixed $response): array
    {
        throw_unless(is_array($response), InvalidArgumentException::class, 'Expected OpenSearch array response.');

        /** @var array<string, mixed> $response */
        return $response;
    }
}
