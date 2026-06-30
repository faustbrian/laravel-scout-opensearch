<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests;

use Cline\LaravelScout\OpenSearch\Tests\Fixtures\SearchableModelHasUuids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Sleep;
use Laravel\Scout\Builder;
use OpenSearch\Client;
use Override;

use function method_exists;
use function trait_exists;

/**
 * @internal
 */
final class ScoutHasUuidsTest extends TestCase
{
    use WithFaker;

    #[Override()]
    public static function setUpBeforeClass(): void
    {
        if (!trait_exists(HasUuids::class)) {
            self::markTestSkipped('Support for HasUuids available since 9.0.');
        }

        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $searchableModelHasUuids = new SearchableModelHasUuids();
        $searchableModelHasUuids->searchableUsing()
            ->createIndex($searchableModelHasUuids->searchableAs());
    }

    protected function tearDown(): void
    {
        $searchableModelHasUuids = new SearchableModelHasUuids();
        $searchableModelHasUuids->searchableUsing()
            ->deleteIndex($searchableModelHasUuids->searchableAs());

        parent::tearDown();
    }

    public function test_search(): void
    {
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 1',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 2',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 3',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 4',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 5',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test search 6',
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'not matched',
        ]);
        Sleep::sleep(2);
        $this->assertCount(6, SearchableModelHasUuids::search('test')->get());
        SearchableModelHasUuids::query()->firstOrFail()->delete();
        Sleep::sleep(2);
        $this->assertCount(5, SearchableModelHasUuids::search('test')->get());
        $this->assertCount(1, SearchableModelHasUuids::search('test')->paginate(2, 'page', 3)->items());

        if (method_exists(Builder::class, 'cursor')) {
            $this->assertCount(5, SearchableModelHasUuids::search('test')->cursor());
        }

        $this->assertCount(5, SearchableModelHasUuids::search('test')->keys());
        SearchableModelHasUuids::removeAllFromSearch();
        Sleep::sleep(2);
        $this->assertCount(0, SearchableModelHasUuids::search('test')->get());
        $this->assertCount(0, SearchableModelHasUuids::search('test')->paginate(2, 'page', 3)->items());

        if (method_exists(Builder::class, 'cursor')) {
            $this->assertCount(0, SearchableModelHasUuids::search('test')->cursor());
        }

        $this->assertCount(0, SearchableModelHasUuids::search('test')->keys());
    }

    public function test_where(): void
    {
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 0,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(3, SearchableModelHasUuids::search('test')->get());
        $this->assertCount(2, SearchableModelHasUuids::search('test')->where('is_visible', 1)->get());

        if (method_exists(Builder::class, 'whereIn')) {
            $this->assertCount(3, SearchableModelHasUuids::search('test')->whereIn('is_visible', [0, 1])->get());
            $this->assertCount(0, SearchableModelHasUuids::search('test')->whereIn('is_visible', [])->get());
        }

        if (!method_exists(Builder::class, 'whereNotIn')) {
            return;
        }

        $this->assertCount(3, SearchableModelHasUuids::search('test')->whereNotIn('is_visible', [])->get());
        $this->assertCount(0, SearchableModelHasUuids::search('test')->whereNotIn('is_visible', [0, 1])->get());
    }

    public function test_callback(): void
    {
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'test',
            'is_visible' => 0,
        ]);
        SearchableModelHasUuids::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(
            3,
            SearchableModelHasUuids::search('test', static fn (Client $client, $query, $options) => $client->search([
                'index' => 'searchable_model_has_uuids',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $query,
                        ],
                    ],
                ],
            ])['hits'])->get(),
        );
        $this->assertCount(
            3,
            SearchableModelHasUuids::search('test', static fn (Client $client, $query, $options) => $client->search([
                'index' => 'searchable_model_has_uuids',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $query,
                        ],
                    ],
                ],
            ]))->get(),
        );
    }
}
