<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Override;

final class CustomKeySearchableModel extends SearchableModel
{
    use HasFactory;

    #[Override()]
    public function getScoutKey(): string
    {
        return 'my-opensearch-key.'.$this->getKey();
    }
}
