<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * A minimal RESP2 client over a plain TCP socket: just the commands the result
 * cache needs (AUTH, SELECT, GET, SET .. EX, SCAN, DEL). It exists because the
 * server may add no Composer runtime package (no predis) and a shared cPanel
 * host cannot be assumed to offer the phpredis extension.
 *
 * Connects lazily, one connection per instance, with one timeout covering
 * connect, write and read. Any failure throws RuntimeException; callers (Cache)
 * treat that as "cache unavailable".
 */
class RedisClient
{
    private string $host;
    private int $port;
    private string $auth;
    private int $db;
    private float $timeout;
    /** @var resource|null */
    private $socket = null;

    public function __construct(string $host, int $port, string $auth = '', int $db = 0, int $timeoutMs = 100)
    {
        $this->host = $host;
        $this->port = $port;
        $this->auth = $auth;
        $this->db = $db;
        $this->timeout = max(1, $timeoutMs) / 1000;
    }

    /** @return string|null the value, or null when the key is missing */
    public function get(string $key): ?string
    {
        $value = $this->command(['GET', $key]);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->command(['SET', $key, $value, 'EX', (string) max(1, $ttlSeconds)]);
    }

    /** Deletes every key starting with $prefix (SCAN + DEL; never KEYS). Returns how many. */
    public function deleteByPrefix(string $prefix): int
    {
        $deleted = 0;
        $cursor = '0';
        do {
            $reply = $this->command(['SCAN', $cursor, 'MATCH', self::escapeGlob($prefix) . '*', 'COUNT', '500']);
            if (!is_array($reply) || count($reply) !== 2 || !is_array($reply[1])) {
                throw new RuntimeException('redis: unexpected SCAN reply');
            }
            $cursor = (string) $reply[0];
            $keys = $reply[1];
            if ($keys !== []) {
                $count = $this->command(array_merge(['DEL'], $keys));
                $deleted += is_int($count) ? $count : 0;
            }
        } while ($cursor !== '0');

        return $deleted;
    }

    /**
     * @param list<string> $arguments
     * @return string|int|list<mixed>|null
     * @throws RuntimeException on connection, protocol or server error
     */
    public function command(array $arguments)
    {
        $socket = $this->connection();
        try {
            $this->write($socket, $arguments);

            return $this->read($socket);
        } catch (RuntimeException $e) {
            $this->close();
            throw $e;
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** @return resource */
    private function connection()
    {
        if ($this->socket !== null) {
            return $this->socket;
        }
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $error,
            $this->timeout
        );
        if ($socket === false) {
            throw new RuntimeException("redis: cannot connect ({$error})");
        }
        $seconds = (int) $this->timeout;
        stream_set_timeout($socket, $seconds, (int) (($this->timeout - $seconds) * 1_000_000));
        $this->socket = $socket;
        try {
            if ($this->auth !== '') {
                $this->command(['AUTH', $this->auth]);
            }
            if ($this->db !== 0) {
                $this->command(['SELECT', (string) $this->db]);
            }
        } catch (RuntimeException $e) {
            $this->close();
            throw $e;
        }

        return $socket;
    }

    /**
     * @param resource $socket
     * @param list<string> $arguments
     */
    private function write($socket, array $arguments): void
    {
        $payload = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $payload .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }
        for ($written = 0, $length = strlen($payload); $written < $length;) {
            $bytes = @fwrite($socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('redis: write failed');
            }
            $written += $bytes;
        }
    }

    /**
     * @param resource $socket
     * @return string|int|list<mixed>|null
     */
    private function read($socket)
    {
        $line = @fgets($socket);
        if ($line === false || !str_ends_with($line, "\r\n")) {
            throw new RuntimeException('redis: read failed or timed out');
        }
        $type = $line[0];
        $body = substr($line, 1, -2);

        switch ($type) {
            case '+':
                return $body;
            case '-':
                throw new RuntimeException('redis: ' . $body);
            case ':':
                return (int) $body;
            case '$':
                $length = (int) $body;
                if ($length < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = @fread($socket, $length + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('redis: read failed or timed out');
                    }
                    $data .= $chunk;
                }

                return substr($data, 0, $length);
            case '*':
                $count = (int) $body;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->read($socket);
                }

                return $items;
            default:
                throw new RuntimeException('redis: unexpected reply');
        }
    }

    /** Glob metacharacters in a literal prefix, for SCAN MATCH. */
    private static function escapeGlob(string $text): string
    {
        return (string) preg_replace('/[\\\\*?\[\]]/', '\\\\$0', $text);
    }
}
