# ONQL PHP Driver

Official PHP client for the ONQL database server.

## Installation

```bash
composer require onql/onql-client
```

## Quick Start

```php
<?php

require_once 'vendor/autoload.php';

use ONQL\ONQLClient;

$client = ONQLClient::create('localhost', 5656);

// Execute a query
$result = $client->sendRequest('onql', json_encode([
    'db' => 'mydb',
    'table' => 'users',
    'query' => 'name = "John"'
]));
echo $result['payload'];

// Close connection
$client->close();
```

## API Reference

### `ONQLClient::create(host, port, options)`

Creates and returns a connected client instance.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$host` | `string` | `"localhost"` | Server hostname |
| `$port` | `int` | `5656` | Server port |
| `$timeout` | `int` | `10` | Default request timeout in seconds |

### `$client->sendRequest(keyword, payload, timeout?)`

Sends a request and waits for a response. Returns an associative array with `request_id`, `source`, and `payload`.

### `$client->close()`

Closes the connection.

## Direct ORM-style API

On top of raw `sendRequest`, the client exposes convenience methods for the
common `insert` / `update` / `delete` / `onql` operations. Each one builds the
standard payload envelope for you and unwraps the `{error, data}` response
automatically — throwing a `\RuntimeException` on error, returning the decoded
`data` field on success.

Call `$client->setup($db)` once to bind a default database name; every
subsequent `insert` / `update` / `delete` / `onql` call will use it.

### `$client->setup(string $db): self`

Sets the default database. Returns `$this`, so calls can be chained.

```php
$client->setup('mydb');
```

### `$client->insert(string $table, $data)`

Insert one record or an array of records.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$table` | `string` | Target table |
| `$data` | `array` | A record (assoc array) or a list of records |

Returns the decoded `data` field from the server response.

```php
$client->insert('users', ['name' => 'John', 'age' => 30]);
$client->insert('users', [['name' => 'A'], ['name' => 'B']]);
```

### `$client->update(string $table, $data, $query, string $protopass = 'default', array $ids = [])`

Update records matching `$query`.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$table` | `string` | — | Target table |
| `$data` | `array` | — | Fields to update |
| `$query` | `array\|string` | — | Match query |
| `$protopass` | `string` | `'default'` | Proto-pass profile |
| `$ids` | `array` | `[]` | Explicit record IDs |

```php
$client->update('users', ['age' => 31], ['name' => 'John']);
$client->update('users', ['active' => false], ['id' => 'u1'], 'admin');
```

### `$client->delete(string $table, $query, string $protopass = 'default', array $ids = [])`

Delete records matching `$query`.

```php
$client->delete('users', ['active' => false]);
```

### `$client->onql(string $query, string $protopass = 'default', string $ctxkey = '', array $ctxvalues = [])`

Run a raw ONQL query. The server's `{error, data}` envelope is unwrapped.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$query` | `string` | — | ONQL query text |
| `$protopass` | `string` | `'default'` | Proto-pass profile |
| `$ctxkey` | `string` | `''` | Context key |
| `$ctxvalues` | `array` | `[]` | Context values |

```php
$rows = $client->onql('select * from users where age > 18');
```

### `$client->build(string $query, ...$values): string`

Replace `$1`, `$2`, … placeholders with values. Strings are automatically
double-quoted; numbers and booleans are inlined verbatim.

```php
$q = $client->build('select * from users where name = $1 and age > $2', 'John', 18);
// -> select * from users where name = "John" and age > 18
$rows = $client->onql($q);
```

### `ONQLClient::processResult(string $raw)`

Static helper that parses the standard `{error, data}` server envelope.
Throws a `\RuntimeException` on non-empty `error`; returns the decoded `data`
on success. Useful if you prefer to build payloads yourself.

### Full example

```php
<?php
require_once 'vendor/autoload.php';

use ONQL\ONQLClient;

$client = ONQLClient::create('localhost', 5656);
$client->setup('mydb');

$client->insert('users', ['name' => 'John', 'age' => 30]);

$rows = $client->onql(
    $client->build('select * from users where age >= $1', 18)
);
print_r($rows);

$client->update('users', ['age' => 31], ['name' => 'John']);
$client->delete('users', ['name' => 'John']);
$client->close();
```

## Protocol

The client communicates over TCP using a delimiter-based message format:

```
<request_id>\x1E<keyword>\x1E<payload>\x04
```

- `\x1E` — field delimiter
- `\x04` — end-of-message marker

## License

MIT
