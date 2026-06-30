<?php declare(strict_types=1);

namespace Cline\LaravelScout\OpenSearch;

use Cline\LaravelScout\OpenSearch\Engines\OpenSearchEngine;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use Override;

use function resolve;

final class OpenSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(EngineManager::class)->extend(
            'opensearch',
            fn (): OpenSearchEngine => new OpenSearchEngine(
                resolve(Client::class),
                Config::boolean('scout.soft_delete'),
            ),
        );
    }

    #[Override()]
    public function register(): void
    {
        $this->app->singleton(
            Client::class,
            static fn (): Client => ClientBuilder::fromConfig(Config::array('scout.opensearch')),
        );
    }
}
