[![GitHub Workflow Status][ico-tests]][link-tests]
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

------

# laravel-scout-opensearch

Laravel Scout custom engine for OpenSearch

## Requirements

> **Requires [PHP 8.5+](https://php.net/releases/)**

## Installation

```bash
composer require cline/laravel-scout-opensearch
```

## Documentation

- **[Getting Started](DOCS.md#doc-docs-readme)** - Installation and Scout setup
- **[Configuration](DOCS.md#doc-docs-configuration)** - OpenSearch client and Scout options
- **[Basic Usage](DOCS.md#doc-docs-basic-usage)** - Search, filters, sorting, and callbacks
- **[Query Behavior](DOCS.md#doc-docs-query-behavior)** - How Scout queries map to OpenSearch
- **[Testing](DOCS.md#doc-docs-testing)** - Local and CI verification commands
- **[Troubleshooting](DOCS.md#doc-docs-troubleshooting)** - Common cluster and indexing issues

## Usage

```php
use App\Models\Product;

$results = Product::search('headphones')
    ->where('is_active', true)
    ->orderBy('id', 'desc')
    ->get();
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please use the [GitHub security reporting form][link-security] rather than the issue queue.

## Credits

- [Brian Faust][link-maintainer]
- [All Contributors][link-contributors]

## License

The MIT License. Please see [License File](LICENSE.md) for more information.

[ico-tests]: https://github.com/faustbrian/laravel-scout-opensearch/actions/workflows/quality-assurance.yaml/badge.svg
[ico-version]: https://img.shields.io/packagist/v/cline/laravel-scout-opensearch.svg
[ico-license]: https://img.shields.io/badge/License-MIT-green.svg
[ico-downloads]: https://img.shields.io/packagist/dt/cline/laravel-scout-opensearch.svg

[link-tests]: https://github.com/faustbrian/laravel-scout-opensearch/actions
[link-packagist]: https://packagist.org/packages/cline/laravel-scout-opensearch
[link-downloads]: https://packagist.org/packages/cline/laravel-scout-opensearch
[link-security]: https://github.com/faustbrian/laravel-scout-opensearch/security
[link-maintainer]: https://github.com/faustbrian
[link-contributors]: ../../contributors
