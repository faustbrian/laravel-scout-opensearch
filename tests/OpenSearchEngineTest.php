<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests;

use Cline\LaravelScout\OpenSearch\Engines\OpenSearchEngine;
use Cline\LaravelScout\OpenSearch\Tests\Fixtures\CustomKeySearchableModel;
use Cline\LaravelScout\OpenSearch\Tests\Fixtures\EmptySearchableModel;
use Cline\LaravelScout\OpenSearch\Tests\Fixtures\SearchableAndSoftDeletesModel;
use Cline\LaravelScout\OpenSearch\Tests\Fixtures\SearchableModel;
use Cline\LaravelScout\OpenSearch\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Mockery as m;
use OpenSearch\Client;
use Override;
use stdClass;

use function class_exists;
use function method_exists;
use function serialize;
use function unserialize;

/**
 * @internal
 */
final class OpenSearchEngineTest extends TestCase
{
    use DatabaseTransactions;

    #[Override()]
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.jobs.tries', m::any())->andReturn(null);
        Config::shouldReceive('get')->with('scout.jobs.backoff', m::any())->andReturn(null);
        Config::shouldReceive('get')->with('scout.jobs.max_exceptions', m::any())->andReturn(null);
        Container::getInstance()->bind('config', static fn () => Config::getFacadeRoot());
    }

    public function test_update_adds_objects_to_index(): void
    {
        $client = m::mock(Client::class);
        $searchableModel = new SearchableModel([
            'id' => 1,
        ]);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'index' => [
                            '_index' => 'table',
                            '_id' => 1,
                        ],
                    ],
                    [
                        'id' => 1,
                        $searchableModel->getScoutKeyName() => $searchableModel->getScoutKey(),
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->update(Collection::make([$searchableModel]));
    }

    public function test_update_with_soft_deletes(): void
    {
        $client = m::mock(Client::class);
        $searchableAndSoftDeletesModel = new SearchableAndSoftDeletesModel([
            'id' => 1,
        ]);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'index' => [
                            '_index' => 'table',
                            '_id' => 1,
                        ],
                    ],
                    [
                        'id' => 1,
                        '__soft_deleted' => 0,
                        $searchableAndSoftDeletesModel->getScoutKeyName() => $searchableAndSoftDeletesModel->getScoutKey(),
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client, true);
        $openSearchEngine->update(Collection::make([$searchableAndSoftDeletesModel]));
    }

    public function test_update_empty(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('bulk');

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->update(Collection::make([]));
    }

    public function test_delete_empty(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('bulk');

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->delete(Collection::make([]));
    }

    public function test_delete_removes_objects_to_index(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 1,
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->delete(Collection::make([
            new SearchableModel([
                'id' => 1,
            ]),
        ]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.5',
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->delete(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key(): void
    {
        if (!class_exists(RemoveFromSearch::class)) {
            $this->markTestSkipped('Support for RemoveFromSearch available since 9.0.');
        }

        $job = new RemoveFromSearch(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));

        $job = unserialize(serialize($job));

        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.5',
                        ],
                    ],
                ],
            ]);
        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->delete($job->models);
    }

    public function test_remove_from_search_job_uses_custom_search_key(): void
    {
        if (!class_exists(RemoveFromSearch::class)) {
            $this->markTestSkipped('Support for RemoveFromSearch available since 9.0.');
        }

        $job = new RemoveFromSearch(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));

        $job = unserialize(serialize($job));

        Container::getInstance()->bind(EngineManager::class, static function () {
            $engine = m::mock(
                new OpenSearchEngine(m::mock(Client::class)),
            );
            $engine->shouldReceive('delete')
                ->once()
                ->with(m::on(static function ($collection): bool {
                    $keyName = ($model = $collection->first())
                        ->getScoutKeyName();

                    return $model->getAttributes()[$keyName] === 'my-opensearch-key.5';
                }));
            $manager = m::mock(EngineManager::class);
            $manager->shouldReceive('engine')
                ->once()
                ->andReturn($engine);

            return $manager;
        });

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_algolia(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                            ],
                            'must_not' => [],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $builder = new Builder(
            new SearchableModel(),
            'zonda',
        );
        $builder->where('foo', 1)
            ->orderBy('id', 'desc');
        $openSearchEngine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_where_in_search(): void
    {
        if (!method_exists(Builder::class, 'whereIn')) {
            $this->markTestSkipped('Support for whereIn available since 9.0.');
        }

        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                                [
                                    'terms' => [
                                        'bar' => [1, 2],
                                    ],
                                ],
                            ],
                            'must_not' => [],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $builder = new Builder(
            new SearchableModel(),
            'zonda',
        );
        $builder->where('foo', 1)
            ->whereIn('bar', [1, 2])
            ->orderBy('id', 'desc');
        $openSearchEngine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_empty_where_in_search(): void
    {
        if (!method_exists(Builder::class, 'whereIn')) {
            $this->markTestSkipped('Support for whereIn available since 9.0.');
        }

        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                                [
                                    'terms' => [
                                        'bar' => [],
                                    ],
                                ],
                            ],
                            'must_not' => [],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $builder = new Builder(
            new SearchableModel(),
            'zonda',
        );
        $builder->where('foo', 1)
            ->whereIn('bar', [])
            ->orderBy('id', 'desc');
        $openSearchEngine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('getScoutModelsByIds')
            ->andReturn($models = Collection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->map($builder, [
            'nbHits' => 1,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertSame([
            '_id' => 1,
        ], $results->first()->scoutMetadata());
    }

    public function test_map_method_respects_order(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('getScoutModelsByIds')
            ->andReturn($models = Collection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
                new SearchableModel([
                    'id' => 2,
                ]),
                new SearchableModel([
                    'id' => 3,
                ]),
                new SearchableModel([
                    'id' => 4,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->map($builder, [
            'nbHits' => 4,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
                [
                    '_id' => 2,
                    'id' => 2,
                ],
                [
                    '_id' => 4,
                    'id' => 4,
                ],
                [
                    '_id' => 3,
                    'id' => 3,
                ],
            ],
        ], $model);

        $this->assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertSame([
            0 => [
                'id' => 1,
            ],
            1 => [
                'id' => 2,
            ],
            2 => [
                'id' => 4,
            ],
            3 => [
                'id' => 3,
            ],
        ], $results->toArray());
    }

    public function test_lazy_map_correctly_maps_results_to_models(): void
    {
        if (!method_exists(Builder::class, 'cursor')) {
            $this->markTestSkipped('Support for cursor available since 9.0.');
        }

        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')
            ->andReturn($models = LazyCollection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->lazyMap($builder, [
            'nbHits' => 1,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertSame([
            '_id' => 1,
        ], $results->first()->scoutMetadata());
    }

    public function test_lazy_map_method_respects_order(): void
    {
        if (!method_exists(Builder::class, 'cursor')) {
            $this->markTestSkipped('Support for cursor available since 9.0.');
        }

        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')
            ->andReturn($models = LazyCollection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
                new SearchableModel([
                    'id' => 2,
                ]),
                new SearchableModel([
                    'id' => 3,
                ]),
                new SearchableModel([
                    'id' => 4,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->lazyMap($builder, [
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
                [
                    '_id' => 2,
                    'id' => 2,
                ],
                [
                    '_id' => 4,
                    'id' => 4,
                ],
                [
                    '_id' => 3,
                    'id' => 3,
                ],
            ],
        ], $model);

        $this->assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertSame([
            0 => [
                'id' => 1,
            ],
            1 => [
                'id' => 2,
            ],
            2 => [
                'id' => 4,
            ],
            3 => [
                'id' => 3,
            ],
        ], $results->toArray());
    }

    public function test_a_model_is_indexed_with_a_custom_algolia_key(): void
    {
        $client = m::mock(Client::class);
        $customKeySearchableModel = new CustomKeySearchableModel([
            'id' => 1,
        ]);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'index' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.1',
                        ],
                    ],
                    [
                        'id' => 1,
                        $customKeySearchableModel->getScoutKeyName() => $customKeySearchableModel->getScoutKey(),
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->update(Collection::make([$customKeySearchableModel]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.1',
                        ],
                    ],
                ],
            ]);

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->delete(Collection::make([
            new CustomKeySearchableModel([
                'id' => 1,
            ]),
        ]));
    }

    public function test_flush_a_model_with_a_custom_algolia_key(): void
    {
        $model = new CustomKeySearchableModel();
        $client = m::mock(Client::class);
        $client->shouldReceive('deleteByQuery')
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'match_all' => new stdClass(),
                    ],
                ],
            ])
            ->andReturn('table');
        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->flush($model);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index(): void
    {
        $client = m::mock(Client::class);

        $client->shouldNotReceive('bulk');

        $openSearchEngine = new OpenSearchEngine($client);
        $openSearchEngine->update(Collection::make([new EmptySearchableModel()]));
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_objects_to_index(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('bulk');

        $openSearchEngine = new OpenSearchEngine($client, true);
        $openSearchEngine->update(Collection::make([new SoftDeletedEmptySearchableModel()]));
    }

    public function test_map_without_hits(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('newCollection')
            ->andReturn($models = Collection::make());

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->map($builder, [
            'nbHits' => 1,
            'hits' => null,
        ], $model);

        $this->assertCount(0, $results);

        $results = $openSearchEngine->map($builder, null, $model);

        $this->assertCount(0, $results);
    }

    public function test_lazy_map_without_hits(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('newCollection')
            ->andReturn($models = Collection::make());

        $builder = m::mock(Builder::class);

        $results = $openSearchEngine->lazyMap($builder, [
            'nbHits' => 1,
            'hits' => null,
        ], $model);

        $this->assertCount(0, $results);

        $results = $openSearchEngine->lazyMap($builder, null, $model);

        $this->assertCount(0, $results);
    }

    public function test_open_search_client_method(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);
        $client->shouldReceive('nodes')
            ->withNoArgs()
            ->once();
        $openSearchEngine->nodes();
    }

    public function test_map_ids(): void
    {
        $client = m::mock(Client::class);
        $openSearchEngine = new OpenSearchEngine($client);

        $results = $openSearchEngine->mapIds(null);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }
}
