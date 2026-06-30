<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
final class SearchableModelHasUuids extends Model
{
    use HasFactory;
    use HasUuids;
    use Searchable;
    use SoftDeletes;

    #[Override()]
    protected $primaryKey = 'uuid';

    #[Override()]
    protected $fillable = ['name', 'is_visible'];

    /**
     * @return array{name: string, is_visible: int}
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'is_visible' => $this->is_visible,
        ];
    }
}
