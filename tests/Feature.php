<?php declare(strict_types=1);

use Cline\LaravelScout\OpenSearch\OpenSearchServiceProvider;

it('loads the package service provider', function (): void {
    expect(class_exists(OpenSearchServiceProvider::class))->toBeTrue();
});
