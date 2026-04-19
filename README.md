# ONQL PHP Driver

Official PHP client for the ONQL database server.

## Installation

### From Packagist (Composer)

```bash
composer require onql/onql-client
```

### From GitHub via Composer VCS repository

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

$client->insert('mydb', 'users', ['id' => 'u1', 'name' => 'John', 'age' => 30]);

$rows = $client->onql('mydb.users[age>18]');
print_r($rows);

$client->update('mydb', 'users', ['age' => 31],
    $client->build('mydb.users[id=$1].id', 'u1'));

$client->delete('mydb', 'users', '', 'default', ['u1']);

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

Sends a raw request frame and waits for a response.

### `$client->close()`

Closes the connection.

## Direct ORM-style API

On top of raw `sendRequest`, the client exposes convenience methods for the
common `insert` / `update` / `delete` / `onql` operations. Each one builds the
standard payload envelope for you and unwraps the `{error, data}` response —
throwing `\RuntimeException` on a non-empty `error`, returning the decoded
`data` field on success.

`db` is passed explicitly to `insert` / `update` / `delete`. `onql` takes a
fully-qualified ONQL expression (which already includes the db name).

`query` arguments are **ONQL expression strings**, e.g.
`'mydb.users[id="u1"].id'`.

### `$client->insert(string $db, string $table, array $data)`

Insert a **single** record.

```php
$client->insert('mydb', 'users', ['id' => 'u1', 'name' => 'John', 'age' => 30]);
```

### `$client->update(string $db, string $table, array $data, string $query = '', string $protopass = 'default', array $ids = [])`

Update records matching `$query` (or the explicit `$ids`).

```php
$client->update('mydb', 'users', ['age' => 31],
    $client->build('mydb.users[id=$1].id', 'u1'));

$client->update('mydb', 'users', ['age' => 31], '', 'default', ['u1']);
```

### `$client->delete(string $db, string $table, string $query = '', string $protopass = 'default', array $ids = [])`

Delete records matching `$query` (or `$ids`).

```php
$client->delete('mydb', 'users',
    $client->build('mydb.users[id=$1].id', 'u1'));

$client->delete('mydb', 'users', '', 'default', ['u1']);
```

### `$client->onql(string $query, string $protopass = 'default', string $ctxkey = '', array $ctxvalues = [])`

Run a raw ONQL query.

```php
$rows = $client->onql('mydb.users[age>18]');
```

### `$client->build(string $query, ...$values): string`

Replace `$1`, `$2`, … placeholders with values.

```php
$q = $client->build('mydb.users[name=$1 and age>$2]', 'John', 18);
$rows = $client->onql($q);
```

### `ONQLClient::processResult(string $raw)`

Static helper that parses the `{error, data}` envelope.

## Protocol

```
<request_id>\x1E<keyword>\x1E<payload>\x04
```

- `\x1E` — field delimiter
- `\x04` — end-of-message marker

## License

MIT
