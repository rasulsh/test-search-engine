<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** public/app_base.php: where the endpoints find bootstrap.php, and how they fail. */
final class AppBaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/appbase_' . uniqid('', true);
        mkdir($this->dir . '/web', 0777, true);
        mkdir($this->dir . '/app', 0777, true);
        copy(dirname(__DIR__) . '/public/app_base.php', $this->dir . '/web/app_base.php');
    }

    protected function tearDown(): void
    {
        InstallerTest::removeDir($this->dir);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string} exit status, stdout
     */
    private function resolve(string $script, array $env = []): array
    {
        $base = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [PHP_BINARY, '-r', 'echo require $argv[1];', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + $base
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }

    public function testDefaultsToTheParentOfPublic(): void
    {
        [$status, $out] = $this->resolve(dirname(__DIR__) . '/public/app_base.php');

        self::assertSame(0, $status);
        self::assertSame(dirname(__DIR__), $out);
    }

    public function testHonoursSearchAppBaseWhenSet(): void
    {
        touch($this->dir . '/app/bootstrap.php');
        [$status, $out] = $this->resolve($this->dir . '/web/app_base.php', ['SEARCH_APP_BASE' => $this->dir . '/app/']);

        self::assertSame(0, $status);
        self::assertSame($this->dir . '/app', $out, 'a trailing slash is trimmed');
    }

    public function testEnvironmentBeatsTheParentWhenBothHaveABootstrap(): void
    {
        touch($this->dir . '/bootstrap.php');
        touch($this->dir . '/app/bootstrap.php');
        [, $out] = $this->resolve($this->dir . '/web/app_base.php', ['SEARCH_APP_BASE' => $this->dir . '/app']);

        self::assertSame($this->dir . '/app', $out);
    }

    public function testParentOfPublicIsUsedWhenTheVariableIsUnsetOrEmpty(): void
    {
        touch($this->dir . '/bootstrap.php');
        foreach ([[], ['SEARCH_APP_BASE' => '']] as $env) {
            [$status, $out] = $this->resolve($this->dir . '/web/app_base.php', $env);
            self::assertSame(0, $status);
            self::assertSame($this->dir, $out);
        }
    }

    public function testClearFailureWhenNeitherHasABootstrap(): void
    {
        [$status, $out] = $this->resolve($this->dir . '/web/app_base.php');

        self::assertNotSame(0, $status);
        self::assertSame('app_base_not_found', json_decode($out, true)['error']);
        self::assertStringContainsString('set SEARCH_APP_BASE', $out);
    }

    public function testClearFailureWhenTheVariablePointsAtTheWrongFolder(): void
    {
        touch($this->dir . '/bootstrap.php'); // the default would work; the explicit setting wins and fails
        [$status, $out] = $this->resolve($this->dir . '/web/app_base.php', ['SEARCH_APP_BASE' => $this->dir . '/nope']);

        self::assertNotSame(0, $status);
        self::assertSame('app_base_not_found', json_decode($out, true)['error']);
    }
}
