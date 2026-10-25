<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * A PHP built-in server running the front controller with a TempConfig of the test's own, for the
 * admin panel's end-to-end tests. Sessions go to a private directory so a test can read or age them.
 */
final class AdminServer
{
    /** @var resource */
    private $process;
    public readonly string $url;
    public readonly string $sessionDir;

    /** @param array<string, mixed> $overrides config.php overrides (the admin section is added) */
    public function __construct(array $overrides)
    {
        $root = dirname(__DIR__, 2);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Cannot allocate a port: {$errstr}");
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $this->sessionDir = TempTree::path('admin_sessions');
        mkdir($this->sessionDir, 0700, true);
        $overrides = array_replace_recursive([
            'admin' => TempConfig::admin() + ['trust_forwarded_proto' => true],
        ], $overrides);

        $process = proc_open(
            [
                PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$port}",
                '-t', $root . '/server/public', $root . '/server/public/index.php',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            ['SEARCH_CONFIG_FILE' => TempConfig::write($overrides)] + getenv()
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start built-in PHP server');
        }
        $this->process = $process;
        $this->url = "http://127.0.0.1:{$port}";

        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents($this->url . '/health', false, $context) !== false) {
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Built-in PHP server did not start in time');
    }

    public function client(bool $https = true): AdminClient
    {
        return new AdminClient($this->url, $https);
    }

    /** The session files the server wrote (id => contents). @return array<string, string> */
    public function sessions(): array
    {
        $found = [];
        foreach (glob($this->sessionDir . '/sess_*') ?: [] as $file) {
            $found[substr(basename($file), 5)] = (string) file_get_contents($file);
        }

        return $found;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        TempTree::remove($this->sessionDir);
    }
}
