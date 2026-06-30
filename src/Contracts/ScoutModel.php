<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;

/**
 * Internal static-analysis contract for Scout-enabled Eloquent models.
 *
 * @internal
 */
interface ScoutModel
{
    public function searchableAs(): string;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array;

    /**
     * @return array<string, mixed>
     */
    public function scoutMetadata(): array;

    public function getScoutKey(): int|string;

    public function getScoutKeyName(): string;

    public function pushSoftDeleteMetadata(): static;

    public function withScoutMetadata(string $key, mixed $value): static;

    /**
     * @param ScoutBuilder<covariant Model> $builder
     * @param list<int|string>              $ids
     *
     * @return EloquentCollection<int, Model>
     */
    public function getScoutModelsByIds(ScoutBuilder $builder, array $ids): EloquentCollection;

    /**
     * @param ScoutBuilder<covariant Model> $builder
     * @param list<int|string>              $ids
     *
     * @return EloquentBuilder<Model>
     */
    public function queryScoutModelsByIds(ScoutBuilder $builder, array $ids): EloquentBuilder;
}
