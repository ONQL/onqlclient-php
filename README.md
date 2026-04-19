# ONQL PHP Driver

Official PHP client for the ONQL database server.

## Installation

### From Packagist (Composer)

```bash
composer require onql/onql-client
```

### From GitHub via Composer VCS repository

Until Packagist indexing is complete, you can install directly from the
GitHub repo by adding a `vcs` repository to your `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ONQL/onqlclient-php" }
    ],
    "require": {
        "onql/onql-client": "dev-main"
    }
}
```

Then:

```bash
composer update onql/onql-client
```

## Quick Start

```php
<?php
require_once 'vendor/autoload.php';

use ONQL\ONQLClient;

$client = ONQLClient::create('localhost', 5656);

$client->insert('mydb.users', ['id' => 'u1', 'name' => 'John', 'age' => 30]);

$rows = $client->onql('select * from mydb.users where age > 18');
print_r($rows);

$client->update('mydb.users.u1', ['age' => 31]);
$client->delete('mydb.users.u1');

$client->close();
```

## API Reference

### `ONQLClient::create(host, port, timeout)`

Creates and returns a connected client instance.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$host` | `string` | `"localhost"` | Server hostname |
| `$port` | `int` | `5656` | Server port |
| `$timeout` | `int` | `10` | Default request timeout in seconds |

### `$client->sendRequest(keyword, payload, timeout?)`

Sends a raw request frame and waits for a response. Returns an associative
array with `request_id`, `source`, and `payload`.

### `$client->close()`

Closes the connection.

## Direct ORM-style API

On top of raw `sendRequest`, the client exposes convenience methods for the
`insert` / `update` / `delete` / `onql` operations. Each one builds the
standard payload envelope for you and unwraps the `{error, data}` response —
throwing a `\RuntimeException` on a non-empty `error`, returning the decoded
`data` field on success.

The `$path` argument is a **dotted string**:

| Path shape | Meaning |
|------------|---------|
| `"mydb.users"` | The `users` table (used by `insert`) |
| `"mydb.users.u1"` | Record id `u1` (used by `update` / `delete`) |

### `$client->insert(string $path, array $data)`

Insert a **single** record.

```php
$client->insert('mydb.users', ['id' => 'u1', 'name' => 'John', 'age' => 30]);
```

### `$client->update(string $path, array $data, string $protopass = 'default')`

Update the record at `$path`.

```php
$client->update('mydb.users.u1', ['age' => 31]);
$client->update('mydb.users.u1', ['active' => false], 'admin');
```

### `$client->delete(string $path, string $protopass = 'default')`

Delete the record at `$path`.

```php
$client->delete('mydb.users.u1');
```

### `$client->onql(string $query, string $protopass = 'default', string $ctxkey = '', array $ctxvalues = [])`

Run a raw ONQL query. The server's `{error, data}` envelope is unwrapped.

```php
$rows = $client->onql('select * from mydb.users where age > 18');
```

### `$client->build(string $query, ...$values): string`

Replace `$1`, `$2`, … placeholders with values. Strings are automatically
double-quoted; numbers and booleans are inlined verbatim.

```php
$q = $client->build(
    'select * from mydb.users where name = $1 and age > $2',
    'John', 18);
$rows = $client->onql($q);
```

### `ONQLClient::processResult(string $raw)`

Static helper that parses the standard `{error, data}` server envelope.
Throws `\RuntimeException` on a non-empty `error`; returns the decoded
`data` on success.

## Protocol

The client communicates over TCP using a delimiter-based message format:

```
<request_id>\x1E<keyword>\x1E<payload>\x04
```

- `\x1E` — field delimiter
- `\x04` — end-of-message marker

## License

MIT
