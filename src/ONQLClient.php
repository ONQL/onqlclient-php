<?php

namespace ONQL;

class ONQLClient
{
    private const EOM = "\x04";
    private const DELIMITER = "\x1E";

    /** @var resource|null */
    private $socket = null;

    private int $defaultTimeout;

    private function __construct(int $defaultTimeout)
    {
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Create and return a connected ONQLClient.
     *
     * @param string $host Server hostname.
     * @param int    $port Server port.
     * @param int    $timeout Default request timeout in seconds.
     * @return self
     * @throws \RuntimeException on connection failure.
     */
    public static function create(string $host = 'localhost', int $port = 5656, int $timeout = 10): self
    {
        $client = new self($timeout);

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \RuntimeException(
                'Failed to create socket: ' . socket_strerror(socket_last_error())
            );
        }

        if (@socket_connect($socket, $host, $port) === false) {
            $err = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new \RuntimeException(
                "Could not connect to server at {$host}:{$port}: {$err}"
            );
        }

        $client->socket = $socket;

        return $client;
    }

    /**
     * Send a request and wait for the matching response.
     *
     * @param string   $keyword The ONQL keyword / command.
     * @param string   $payload The request payload (typically JSON).
     * @param int|null $timeout Per-request timeout override in seconds.
     * @return array{request_id: string, source: string, payload: string}
     * @throws \RuntimeException on connection or protocol errors.
     * @throws \RuntimeException on timeout.
     */
    public function sendRequest(string $keyword, string $payload, ?int $timeout = null): array
    {
        if ($this->socket === null) {
            throw new \RuntimeException('Client is not connected.');
        }

        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $requestId = $this->generateRequestId();

        // Build the wire message: {request_id}\x1E{keyword}\x1E{payload}\x04
        $message = $requestId . self::DELIMITER . $keyword . self::DELIMITER . $payload . self::EOM;

        // Send the full message
        $this->socketWriteAll($message);

        // Set socket read timeout
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec'  => $effectiveTimeout,
            'usec' => 0,
        ]);

        // Read until we receive the EOM byte
        $buffer = '';
        $deadline = microtime(true) + $effectiveTimeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException(
                    "Request {$requestId} timed out after {$effectiveTimeout}s."
                );
            }

            $chunk = @socket_read($this->socket, 65536, PHP_BINARY_READ);

            if ($chunk === false) {
                $errno = socket_last_error($this->socket);
                socket_clear_error($this->socket);
                // EAGAIN / EWOULDBLOCK or timeout
                if ($errno === SOCKET_EAGAIN || $errno === SOCKET_EWOULDBLOCK || $errno === 11) {
                    throw new \RuntimeException(
                        "Request {$requestId} timed out after {$effectiveTimeout}s."
                    );
                }
                throw new \RuntimeException(
                    'Socket read error: ' . socket_strerror($errno)
                );
            }

            if ($chunk === '') {
                throw new \RuntimeException('Connection closed by server.');
            }

            $buffer .= $chunk;

            // Check if we have a complete message (contains EOM)
            $eomPos = strpos($buffer, self::EOM);
            if ($eomPos !== false) {
                $rawResponse = substr($buffer, 0, $eomPos);
                break;
            }
        }

        // Parse: {request_id}\x1E{source}\x1E{payload}
        $parts = explode(self::DELIMITER, $rawResponse, 3);
        if (count($parts) !== 3) {
            throw new \RuntimeException(
                'Malformed response from server (expected 3 fields, got ' . count($parts) . ').'
            );
        }

        return [
            'request_id' => $parts[0],
            'source'     => $parts[1],
            'payload'    => $parts[2],
        ];
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Generate a random 8-character hex request ID.
     */
    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(4));
    }

    // ----------------------------------------------------------------
    // Direct ORM-style API (insert / update / delete / onql / build)
    //
    // `$path` is a dotted string:
    //   "mydb.users"        -> table `users` in database `mydb`
    //   "mydb.users.u1"     -> record with id `u1`
    // ----------------------------------------------------------------

    /**
     * Parse "db.table" or "db.table.id" into [$db, $table, $id].
     * @return array{0:string,1:string,2:string}
     */
    private static function parsePath(string $path, bool $requireId): array
    {
        if ($path === '') {
            throw new \InvalidArgumentException(
                'Path must be a non-empty string like "db.table" or "db.table.id"');
        }
        $parts = explode('.', $path, 3);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(
                'Path "' . $path . '" must contain at least "db.table"');
        }
        [$db, $table] = $parts;
        $id = $parts[2] ?? '';
        if ($requireId && $id === '') {
            throw new \InvalidArgumentException(
                'Path "' . $path . '" must include a record id: "db.table.id"');
        }
        return [$db, $table, $id];
    }

    /**
     * Parse the standard {error, data} envelope returned by the server.
     * Throws \RuntimeException if `error` is non-empty.
     *
     * @return mixed The decoded `data` field (may be scalar, array, or null).
     */
    public static function processResult(string $raw)
    {
        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException($raw);
        }
        if (!empty($decoded['error'])) {
            throw new \RuntimeException((string)$decoded['error']);
        }
        return $decoded['data'] ?? null;
    }

    /**
     * Insert a single record at $path (e.g. "mydb.users").
     *
     * @param string $path  Table path.
     * @param array  $data  A single record (associative array).
     * @return mixed Decoded `data` field from the server envelope.
     */
    public function insert(string $path, array $data)
    {
        [$db, $table] = self::parsePath($path, false);
        if (array_is_list($data)) {
            throw new \InvalidArgumentException('insert() expects a single record object, not a list');
        }
        $payload = json_encode([
            'db'      => $db,
            'table'   => $table,
            'records' => $data,
        ]);
        $res = $this->sendRequest('insert', $payload);
        return self::processResult($res['payload']);
    }

    /**
     * Update the record at $path (e.g. "mydb.users.u1").
     */
    public function update(string $path, array $data, string $protopass = 'default')
    {
        [$db, $table, $id] = self::parsePath($path, true);
        $payload = json_encode([
            'db'        => $db,
            'table'     => $table,
            'records'   => $data,
            'query'     => '',
            'protopass' => $protopass,
            'ids'       => [$id],
        ]);
        $res = $this->sendRequest('update', $payload);
        return self::processResult($res['payload']);
    }

    /**
     * Delete the record at $path (e.g. "mydb.users.u1").
     */
    public function delete(string $path, string $protopass = 'default')
    {
        [$db, $table, $id] = self::parsePath($path, true);
        $payload = json_encode([
            'db'        => $db,
            'table'     => $table,
            'query'     => '',
            'protopass' => $protopass,
            'ids'       => [$id],
        ]);
        $res = $this->sendRequest('delete', $payload);
        return self::processResult($res['payload']);
    }

    /**
     * Execute a raw ONQL query and return the decoded `data` payload.
     */
    public function onql(string $query, string $protopass = 'default', string $ctxkey = '', array $ctxvalues = [])
    {
        $payload = json_encode([
            'query'     => $query,
            'protopass' => $protopass,
            'ctxkey'    => $ctxkey,
            'ctxvalues' => $ctxvalues,
        ]);
        $res = $this->sendRequest('onql', $payload);
        return self::processResult($res['payload']);
    }

    /**
     * Replace $1, $2, ... placeholders in $query with the supplied values.
     * Strings are double-quoted; numbers/booleans are inlined verbatim.
     */
    public function build(string $query, ...$values): string
    {
        foreach ($values as $i => $value) {
            $placeholder = '$' . ($i + 1);
            if (is_bool($value)) {
                $replacement = $value ? 'true' : 'false';
            } elseif (is_string($value)) {
                $replacement = '"' . $value . '"';
            } elseif (is_int($value) || is_float($value)) {
                $replacement = (string)$value;
            } else {
                $replacement = (string)$value;
            }
            $query = str_replace($placeholder, $replacement, $query);
        }
        return $query;
    }

    /**
     * Write all bytes to the socket, handling partial writes.
     *
     * @throws \RuntimeException on write failure.
     */
    private function socketWriteAll(string $data): void
    {
        $totalLen = strlen($data);
        $written = 0;

        while ($written < $totalLen) {
            $result = @socket_write($this->socket, substr($data, $written));
            if ($result === false) {
                throw new \RuntimeException(
                    'Socket write error: ' . socket_strerror(socket_last_error($this->socket))
                );
            }
            $written += $result;
        }
    }
}
