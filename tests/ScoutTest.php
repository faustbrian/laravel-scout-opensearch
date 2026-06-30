<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch\Tests;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Sleep;
use Laravel\Scout\Builder;
use Laravel\Scout\Scout;
use OpenSearch\Client;

use function class_exists;
use function method_exists;
use function version_compare;

/**
 * @internal
 */
final class ScoutTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        $searchableModel = new SearchableModel();
        $searchableModel->searchableUsing()
            ->createIndex($searchableModel->searchableAs());
    }

    protected function tearDown(): void
    {
        $searchableModel = new SearchableModel();
        $searchableModel->searchableUsing()
            ->deleteIndex($searchableModel->searchableAs());

        parent::tearDown();
    }

    public function test_search(): void
    {
        SearchableModel::query()->create([
            'name' => 'test search 1',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 2',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 3',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 4',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 5',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 6',
        ]);
        SearchableModel::query()->create([
            'name' => 'not matched',
        ]);
        Sleep::sleep(2);
        $this->assertCount(6, SearchableModel::search('test')->get());
        SearchableModel::query()->firstOrFail()->delete();
        Sleep::sleep(2);
        $this->assertCount(5, SearchableModel::search('test')->get());
        $this->assertCount(1, SearchableModel::search('test')->paginate(2, 'page', 3)->items());

        if (method_exists(Builder::class, 'cursor')) {
            $this->assertCount(5, SearchableModel::search('test')->cursor());
        }

        $this->assertCount(5, SearchableModel::search('test')->keys());
        SearchableModel::removeAllFromSearch();
        Sleep::sleep(2);
        $this->assertCount(0, SearchableModel::search('test')->get());
        $this->assertCount(0, SearchableModel::search('test')->paginate(2, 'page', 3)->items());

        if (method_exists(Builder::class, 'cursor')) {
            $this->assertCount(0, SearchableModel::search('test')->cursor());
        }

        $this->assertCount(0, SearchableModel::search('test')->keys());
    }

    public function test_order_by(): void
    {
        SearchableModel::query()->create([
            'name' => 'test search 1',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 2',
        ]);
        SearchableModel::query()->create([
            'name' => 'test search 3',
        ]);
        Sleep::sleep(2);
        $this->assertSame(3, SearchableModel::search('test')->orderBy('id', 'desc')->first()->getKey());
        $this->assertSame(1, SearchableModel::search('test')->orderBy('id')->first()->getKey());
        $this->assertSame(3, SearchableModel::search('test')->orderBy('id', 'desc')->first()->getKey());
    }

    public function test_where(): void
    {
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 0,
        ]);
        SearchableModel::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(3, SearchableModel::search('test')->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', 1)->get());

        if (method_exists(Builder::class, 'whereIn')) {
            $this->assertCount(3, SearchableModel::search('test')->whereIn('is_visible', [0, 1])->get());
            $this->assertCount(0, SearchableModel::search('test')->whereIn('is_visible', [])->get());
        }

        if (!method_exists(Builder::class, 'whereNotIn')) {
            return;
        }

        $this->assertCount(3, SearchableModel::search('test')->whereNotIn('is_visible', [])->get());
        $this->assertCount(0, SearchableModel::search('test')->whereNotIn('is_visible', [0, 1])->get());
    }

    public function test_callback(): void
    {
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 0,
        ]);
        SearchableModel::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(
            3,
            SearchableModel::search('test', static fn (Client $client, $query, $options) => $client->search([
                'index' => 'searchable-model',
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
            SearchableModel::search('test', static fn (Client $client, $query, $options) => $client->search([
                'index' => 'searchable-model',
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

    public function test_where_null(): void
    {
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => true,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => false,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => null,
        ]);
        SearchableModel::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(3, SearchableModel::search('test')->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', true)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', false)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', null)->get());
    }

    public function test_where_with_operator(): void
    {
        if (!class_exists(Scout::class) || version_compare(Scout::VERSION, '11.0.0') === -1) {
            $this->markTestSkipped('Support for whereIn available since 11.0.');
        }

        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 1,
        ]);
        SearchableModel::query()->create([
            'name' => 'test',
            'is_visible' => 0,
        ]);
        SearchableModel::query()->create([
            'name' => 'nothing',
        ]);
        Sleep::sleep(2);
        $this->assertCount(2, SearchableModel::search('test')->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', 1)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', 0)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '=', 1)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '=', 0)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '!=', 1)->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', '!=', -1)->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', '>', -1)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '>', 0)->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', '<', 2)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '<', 1)->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', '>=', 0)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '>=', 1)->get());
        $this->assertCount(2, SearchableModel::search('test')->where('is_visible', '<=', 1)->get());
        $this->assertCount(1, SearchableModel::search('test')->where('is_visible', '<=', 0)->get());
    }
}
