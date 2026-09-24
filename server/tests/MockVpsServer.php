<?php

declare(strict_types=1);

namespace App\Tests;

use RuntimeException;

/**
 * A `php -S` mock VPS vector service (mock_vps.php + FakeVps) on a free local
 * port. The scenario can be swapped between requests; the last request cPanel
 * sent is readable for assertions.
 */
final class MockVpsServer
{
    public readonly string $url;
    private readonly string $scenarioPath;
    /** @var resource */
    private $process;

    /** @param array<string, mixed> $scenario */
    public function __construct(array $scenario)
    {
        $this->scenarioPath = (string) tempnam(sys_get_temp_dir(), 'mockvps_');
        $this->setScenario($scenario);
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/mock_vps.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__,
            ['MOCK_VPS_SCENARIO' => $this->scenarioPath] + getenv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start the mock VPS');
        }
        $this->process = $process;
        $this->url = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('the mock VPS did not start in time');
    }

    /** @param array<string, mixed> $scenario */
    public function setScenario(array $scenario): void
    {
        file_put_contents($this->scenarioPath, (string) json_encode($scenario));
        @unlink($this->scenarioPath . '.last');
    }

    /** @return array{path?: string, authorization?: string, body?: mixed} {} before any request */
    public function lastRequest(): array
    {
        $last = @file_get_contents($this->scenarioPath . '.last');

        return $last === false ? [] : (array) json_decode($last, true);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->scenarioPath);
        @unlink($this->scenarioPath . '.last');
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("cannot allocate a port: {$errstr}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
