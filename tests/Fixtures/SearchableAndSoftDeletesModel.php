<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Override;

final class SearchableAndSoftDeletesModel extends Model
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    #[Override()]
    protected $fillable = ['id'];

    public function searchableAs(): string
    {
        return 'table';
    }
}
