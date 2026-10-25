<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** server/tools/config-check.php: the exit status deploy scripts and CI gate on. */
final class ConfigCheckToolTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array{int, string}
     */
    private function runTool(array $overrides, string ...$flags): array
    {
        $file = TempConfig::write($overrides);
        $env = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/config-check.php', '--file=' . $file, ...$flags],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }

    public function testExitsZeroOnACleanConfig(): void
    {
        [$status, $out] = $this->runTool([
            'db' => ['dsn' => 'mysql:host=h;dbname=d', 'user' => 'u'],
            'reload' => ['token' => 't'],
            'admin' => TempConfig::admin(),
        ]);

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('config is clean', $out);
        self::assertStringNotContainsString('INFO', $out);
    }

    public function testExitsNonZeroOnAnError(): void
    {
        [$status, $out] = $this->runTool(['reload' => ['token' => 't']]);

        self::assertSame(1, $status);
        self::assertStringContainsString('ERROR  db.dsn', $out);
    }

    public function testWarningsDoNotFailAndVerboseListsEveryKeyWithItsSource(): void
    {
        [$status, $out] = $this->runTool(
            ['db' => ['dsn' => 'x', 'user' => 'u'], 'search' => ['title_weight' => 7.5], 'typo' => 1],
            '--verbose'
        );

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('WARN   typo: unknown key', $out);
        // A key config.php sets, with its value, and one left at the code default.
        self::assertStringContainsString('INFO   search.title_weight = 7.5 [config.php]', $out);
        self::assertMatchesRegularExpression('/^INFO   search\.desc_weight = \S+ \[code default\]$/m', $out);
        self::assertStringContainsString("INFO   admin.username = '' [code default]", $out);
        // An unknown key is warned about, not listed.
        self::assertStringNotContainsString('INFO   typo', $out);
    }

    public function testVerboseNeverPrintsACredentialOnlyWhetherItIsSet(): void
    {
        [, $out] = $this->runTool([
            'db' => ['dsn' => 'mysql:host=secret-host;dbname=d', 'user' => 'secret-user', 'password' => 'secret-pass'],
            'reload' => ['token' => 'secret-token'],
            'admin' => TempConfig::admin(),
        ], '--verbose');

        foreach (['secret-host', 'secret-user', 'secret-pass', 'secret-token', TempConfig::ADMIN_PASSWORD] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
        self::assertStringContainsString('INFO   db.password = set [config.php]', $out);
        self::assertStringContainsString('INFO   reload.token = set [config.php]', $out);
        self::assertStringContainsString('INFO   admin.password_hash = set [config.php]', $out);
        self::assertStringContainsString('INFO   vps.token = empty [code default]', $out);
    }

    public function testWithoutVerboseNoValueIsPrinted(): void
    {
        [, $out] = $this->runTool(['db' => ['dsn' => 'x', 'user' => 'u'], 'search' => ['title_weight' => 7.5]]);
        self::assertStringNotContainsString('7.5', $out);
        self::assertStringNotContainsString('INFO', $out);
    }
}
