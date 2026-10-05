<?php

declare(strict_types=1);

namespace App\Tests;

/** fake_redis_server.php on a free local port, for tests that need a real socket. */
final class FakeRedisServer
{
    public readonly int $port;
    /** @var resource|null */
    private $process;

    public function __construct(string $password = '')
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake_redis_server.php', (string) $this->port, $password],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            if (($socket = @fsockopen('127.0.0.1', $this->port)) !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        $this->stop();
        throw new \RuntimeException('the fake redis server did not start in time');
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
