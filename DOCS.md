## Table of Contents

1. [Getting Started](#doc-docs-readme)
2. [Configuration](#doc-docs-configuration)
3. [Basic Usage](#doc-docs-basic-usage)
4. [Query Behavior](#doc-docs-query-behavior)
5. [Testing](#doc-docs-testing)
6. [Troubleshooting](#doc-docs-troubleshooting)
<a id="doc-docs-readme"></a>

# Getting Started

Laravel Scout OpenSearch provides a Scout engine implementation backed by
the official OpenSearch PHP client.

## Requirements

This package requires PHP 8.5+, Laravel Scout 11 or 12, and a reachable
OpenSearch cluster.

## Installation

Install the package with Composer:

```bash
composer require cline/laravel-scout-opensearch
```

The package uses Laravel package discovery, so the service provider is
registered automatically.

## Configure Scout

Set Scout to use the `opensearch` driver and provide the OpenSearch
client configuration under `scout.opensearch`:

```php
return [
    'driver' => env('SCOUT_DRIVER', 'opensearch'),

    'opensearch' => [
        'hosts' => [
            env('OPENSEARCH_HOST', '127.0.0.1:9200'),
        ],
        'retries' => 2,
        'basicAuthentication' => [
            env('OPENSEARCH_USERNAME', 'admin'),
            env('OPENSEARCH_PASSWORD', 'admin'),
        ],
        'sslVerification' => env('OPENSEARCH_SSL_VERIFICATION', false),
    ],
];
```

At runtime, the package builds an `OpenSearch\Client` from the
`scout.opensearch` array and registers the engine as `opensearch`.

## Make a Model Searchable

Add Scout's `Searchable` trait to an Eloquent model and define the index
name plus searchable payload:

```php
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

final class Product extends Model
{
    use Searchable;

    protected $fillable = ['name', 'sku', 'is_active'];

    public function searchableAs(): string
    {
        return 'products';
    }

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'is_active' => $this->is_active,
        ];
    }
}
```

## Create the Index

Before running searches, create the index in OpenSearch:

```php
$model = new Product();

$model->searchableUsing()->createIndex($model->searchableAs());
```

Once the index exists, normal Scout indexing flows apply:

```php
Product::create([
    'name' => 'Noise Cancelling Headphones',
    'sku' => 'NC-1000',
    'is_active' => true,
]);
```

<a id="doc-docs-configuration"></a>

# Configuration

The package reads all client options from `config/scout.php` under the
`opensearch` key.

## Supported Client Options

Any option accepted by `OpenSearch\ClientBuilder::fromConfig(...)` may be
passed through. Common options:

- `hosts` - one or more OpenSearch hosts
- `retries` - retry count for failed requests
- `basicAuthentication` - username and password pair
- `sslVerification` - whether to verify TLS certificates
- `handler` - custom HTTP handler stack

Example with multiple nodes:

```php
'opensearch' => [
    'hosts' => [
        env('OPENSEARCH_HOST_1', '10.0.0.10:9200'),
        env('OPENSEARCH_HOST_2', '10.0.0.11:9200'),
    ],
    'retries' => 2,
    'basicAuthentication' => [
        env('OPENSEARCH_USERNAME'),
        env('OPENSEARCH_PASSWORD'),
    ],
    'sslVerification' => true,
],
```

## Soft Deletes

If your application enables Scout soft-delete support via
`scout.soft_delete`, the engine includes Scout's soft delete metadata in
indexed documents for models using `SoftDeletes`.

That lets Scout preserve soft-delete awareness instead of dropping the
metadata during indexing.

## Scout Keys and Metadata

The engine respects custom Scout keys, so models overriding
`getScoutKey()` or `getScoutKeyName()` continue to work.

It also preserves hit metadata with underscore-prefixed keys like `_id`
when mapping search results back to Eloquent models.

<a id="doc-docs-basic-usage"></a>

# Basic Usage

## Basic Search

Use Scout exactly as you would with any other engine:

```php
$results = Product::search('headphones')->get();
```

## Pagination

Pagination uses OpenSearch `from` and `size` under the hood:

```php
$products = Product::search('headphones')->paginate(15);
```

## Sorting

The engine translates Scout `orderBy()` calls into OpenSearch sort
clauses:

```php
$latest = Product::search('headphones')
    ->orderBy('id', 'desc')
    ->get();
```

## Filtering

Equality filters:

```php
$active = Product::search('headphones')
    ->where('is_active', true)
    ->get();
```

Operator-based filters:

```php
$active = Product::search('headphones')
    ->where('inventory', '>', 0)
    ->get();
```

`whereIn()` and `whereNotIn()` filters are also supported when your
installed Scout version exposes those builder methods:

```php
$products = Product::search('headphones')
    ->whereIn('category_id', [10, 11, 12])
    ->whereNotIn('brand_id', [99])
    ->get();
```

`null` values are translated into OpenSearch `exists` checks so you can
query for missing fields too:

```php
$withoutVisibility = Product::search('headphones')
    ->where('is_active', null)
    ->get();
```

## Custom Search Callback

If you need full control, pass a callback to Scout's `search()` method.
The callback receives the OpenSearch client, the original query string,
and the generated options array:

```php
use OpenSearch\Client;

$results = Product::search(
    'headphones',
    static function (Client $client, string $query, array $options): array {
        return $client->search([
            'index' => 'products',
            'body' => [
                'query' => [
                    'query_string' => [
                        'query' => $query,
                    ],
                ],
            ],
        ]);
    },
)->get();
```

## Managing Indexes

The engine exposes helpers for index lifecycle operations:

```php
$engine = (new Product())->searchableUsing();

$engine->createIndex('products');
$engine->deleteIndex('products');
$engine->flush(new Product());
```

<a id="doc-docs-query-behavior"></a>

# Query Behavior

The engine converts Scout builder state into an OpenSearch bool query.

## Default Query

A basic search becomes a `query_string` clause:

```php
Product::search('headphones')->get();
```

This produces a bool query with the search text in `must`.

## Where Clauses

- `where('field', $value)` becomes a `term` query
- `where('field', null)` becomes an `exists` or `must_not exists` query
- `where('field', '>', 0)` and similar operators become `range` queries
- `whereIn()` becomes `terms`
- `whereNotIn()` becomes `must_not` `terms`

## Result Mapping

When OpenSearch returns hits, the engine:

- extracts hit ids from `_id`
- loads Eloquent models through Scout's `getScoutModelsByIds()` or
  `queryScoutModelsByIds()`
- restores the OpenSearch hit order
- copies underscore-prefixed hit metadata onto the model via
  `scoutMetadata()`

That means custom keys, lazy cursors, and hit metadata all survive the
round-trip back into Eloquent collections.

<a id="doc-docs-testing"></a>

# Testing

Run the package checks with Composer:

```bash
composer test
```

Individual checks:

```bash
composer test:lint
composer test:type-coverage
composer test:unit
composer test:types
composer test:refactor
```

The integration tests expect a reachable OpenSearch node. In local Docker
setups, provide `OPENSEARCH_HOST` so the PHP container can reach your
cluster.

Example:

```bash
export OPENSEARCH_HOST=127.0.0.1:9200
vendor/bin/pest
```

<a id="doc-docs-troubleshooting"></a>

# Troubleshooting

## `No alive nodes found in your cluster`

The OpenSearch client could not connect to any configured node.

Check:

- `scout.opensearch.hosts` points to a reachable host and port
- authentication credentials are correct
- the OpenSearch node is healthy before running searches or tests

## Searches Return No Results

Check:

- the model implements `toSearchableArray()` with the fields you query
- the model has been indexed with Scout
- the index exists before the first search

## Soft Deleted Models Behave Unexpectedly

If you rely on Scout soft-delete behavior, ensure:

- the model uses Laravel's `SoftDeletes` trait
- `scout.soft_delete` is enabled in your Scout configuration

## Custom Key Models Do Not Match Results

If you override Scout keys, confirm that:

- `getScoutKey()` returns the exact document id stored in OpenSearch
- `getScoutKeyName()` matches the identifier field you expect to index
