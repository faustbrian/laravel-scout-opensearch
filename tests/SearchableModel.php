<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Override;

/**
 * @property int    $is_visible
 * @property string $name
 *
 * @method static static|Builder<static>|\Illuminate\Database\Query\Builder query()
 */
final class SearchableModel extends Model
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    #[Override()]
    protected $fillable = ['name', 'is_visible'];

    public function searchableAs(): string
    {
        return 'searchable-model';
    }

    /**
     * @return array{id: mixed}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getScoutKey(),
            'name' => $this->name,
            'is_visible' => $this->is_visible,
        ];
    }
}
