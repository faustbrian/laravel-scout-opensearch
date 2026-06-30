<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Override;

final class SoftDeletedEmptySearchableModel extends SearchableModel
{
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    #[Override()]
    public function toSearchableArray(): array
    {
        return [];
    }

    /**
     * @return array{__soft_deleted: int}
     */
    #[Override()]
    public function scoutMetadata(): array
    {
        return [
            '__soft_deleted' => 1,
        ];
    }
}
