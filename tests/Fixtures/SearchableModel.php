<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Override;

class SearchableModel extends Model
{
    use HasFactory;
    use Searchable;

    #[Override()]
    protected $fillable = ['id'];

    public function searchableAs(): string
    {
        return 'table';
    }
}
