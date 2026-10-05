<?php

/**
 * A tiny RESP2 server for RedisClientTest (CI has no Redis): php fake_redis_server.php PORT [PASSWORD].
 * Supports AUTH, SELECT, GET, SET .. EX, SCAN (pages of 2 keys; the cursor is the
 * last key returned, so cursors are exercised and deleting between pages is safe), DEL,
 * PING, and `STALL`, which never answers (a hung server). Serves several clients.
 */

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$password = $argv[2] ?? '';
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}

/** @var array<string, string> $data */
$data = [];

$readCommand = static function ($conn): ?array {
    $header = fgets($conn);
    if ($header === false || $header[0] !== '*') {
        return null;
    }
    $args = [];
    for ($i = (int) substr($header, 1); $i > 0; $i--) {
        $length = (int) substr((string) fgets($conn), 1);
        $args[] = substr((string) stream_get_contents($conn, $length + 2), 0, $length);
    }

    return $args;
};
$bulk = static fn (?string $v): string => $v === null ? "$-1\r\n" : '$' . strlen($v) . "\r\n{$v}\r\n";

/** @var array<int, array{0: resource, 1: bool}> $clients connection => [stream, authenticated] */
$clients = [];
while (true) {
    $read = array_merge([$server], array_map(static fn (array $c) => $c[0], $clients));
    $write = $except = null;
    if (stream_select($read, $write, $except, null) === false) {
        exit(1);
    }
    foreach ($read as $conn) {
        if ($conn === $server) {
            $new = stream_socket_accept($server);
            $clients[(int) $new] = [$new, $password === ''];
            continue;
        }
        $id = (int) $conn;
        $args = $readCommand($conn);
        if ($args === null) {
            fclose($conn);
            unset($clients[$id]);
            continue;
        }
        $name = strtoupper($args[0]);
        if ($name === 'AUTH') {
            $clients[$id][1] = $args[1] === $password;
            fwrite($conn, $clients[$id][1] ? "+OK\r\n" : "-WRONGPASS invalid password\r\n");
        } elseif (!$clients[$id][1]) {
            fwrite($conn, "-NOAUTH Authentication required.\r\n");
        } elseif ($name === 'SELECT' || $name === 'PING') {
            fwrite($conn, "+OK\r\n");
        } elseif ($name === 'GET') {
            fwrite($conn, $bulk($data[$args[1]] ?? null));
        } elseif ($name === 'SET') {
            $data[$args[1]] = $args[2];
            fwrite($conn, "+OK\r\n");
        } elseif ($name === 'DEL') {
            $n = 0;
            foreach (array_slice($args, 1) as $key) {
                $n += isset($data[$key]) ? 1 : 0;
                unset($data[$key]);
            }
            fwrite($conn, ":{$n}\r\n");
        } elseif ($name === 'SCAN') {
            $keys = array_keys($data);
            sort($keys);
            $after = $args[1] === '0' ? '' : $args[1];
            $keys = array_values(array_filter($keys, static fn (string $k): bool => $k > $after));
            $page = array_slice($keys, 0, 2);
            $next = count($keys) > 2 ? end($page) : '0';
            $out = "*2\r\n" . $bulk($next) . '*' . count(array_filter(
                $page,
                static fn (string $k): bool => fnmatch($args[3], $k)
            )) . "\r\n";
            foreach ($page as $key) {
                if (fnmatch($args[3], $key)) {
                    $out .= $bulk($key);
                }
            }
            fwrite($conn, $out);
        } elseif ($name !== 'STALL') {
            fwrite($conn, "-ERR unknown command\r\n");
        }
    }
}
