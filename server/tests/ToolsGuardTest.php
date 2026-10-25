<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tools/*.php check the PHP version before anything else, so an account whose default `php` is old
 * gets a clear English message instead of a fatal error from src/.
 */
final class ToolsGuardTest extends TestCase
{
    private static function toolsDir(): string
    {
        return dirname(__DIR__) . '/tools';
    }

    /** @return array<string, array{string}> */
    public static function tools(): array
    {
        $tools = [];
        foreach (glob(self::toolsDir() . '/*.php') ?: [] as $file) {
            if (basename($file) !== '_php-guard.php') {
                $tools[basename($file)] = [$file];
            }
        }

        return $tools;
    }

    public function testThereAreToolsToCheck(): void
    {
        self::assertGreaterThanOrEqual(4, count(self::tools()));
    }

    public function testTheMessageNamesTheVersionTheBinaryAndTheFix(): void
    {
        require_once self::toolsDir() . '/_php-guard.php';

        self::assertNull(search_tool_php_problem(80100, '8.1.0', '/usr/bin/php'));
        self::assertNull(search_tool_php_problem(80304, '8.3.4', '/usr/bin/php'));
        foreach ([[70433, '7.4.33'], [80000, '8.0.0'], [50600, '5.6.40']] as [$id, $version]) {
            $message = (string) search_tool_php_problem($id, $version, '/usr/bin/php');

            self::assertStringContainsString('PHP 8.1 or newer is required', $message);
            self::assertStringContainsString("this is PHP {$version} (/usr/bin/php)", $message);
            self::assertStringContainsString('ea-php82', $message);
            self::assertStringContainsString('not as root', $message);
            self::assertStringEndsWith("\n", $message);
            self::assertSame(0, preg_match('/[^\x00-\x7F]/', $message), 'English / ASCII only');
        }
    }

    #[DataProvider('tools')]
    public function testEveryToolRequiresTheGuardBeforeAnyOtherCode(string $file): void
    {
        $code = (string) file_get_contents($file);
        $guard = strpos($code, "require __DIR__ . '/_php-guard.php';");

        self::assertNotFalse($guard, basename($file) . ' requires the guard');
        $before = substr($code, 0, (int) $guard);
        // Only the header (comments, declare, use) may come first: no other statement can fail on an old PHP.
        $header = ['#/\*.*?\*/#s', '#//[^\n]*#', '/^<\?php\s*/', '/declare\(strict_types=1\);/', '/^use [^;]+;$/m'];
        $statements = preg_replace($header, '', $before);
        self::assertSame('', trim((string) $statements), basename($file) . ' runs nothing before the guard');
        self::assertStringContainsString('declare(strict_types=1);', $code);
    }

    #[DataProvider('tools')]
    public function testToolsStayParseableByPhp70(string $file): void
    {
        $code = (string) file_get_contents($file);
        // Syntax newer than PHP 7.0 would stop an old PHP before the guard can speak.
        $patterns = [
            'trailing comma in a call' => '/,\s*\n\s*\)/',
            'catch without a variable' => '/catch \([\w\\\\| ]+\)/',
            'nullsafe operator' => '/\?->/',
            'match expression' => '/\bmatch\s*\(/',
            'arrow function' => '/\bfn\s*\(/',
            'nullable type' => '/\?\w+\s+\$|\)\s*:\s*\?/',
            'attribute' => '/#\[/',
            'void return type' => '/\)\s*:\s*void\b/',
        ];
        foreach ($patterns as $name => $pattern) {
            self::assertSame(0, preg_match($pattern, $code), basename($file) . ': ' . $name);
        }
    }

    public function testTheGuardItselfIsPhp5SafeAndOnlyTalksInCli(): void
    {
        $code = (string) file_get_contents(self::toolsDir() . '/_php-guard.php');

        self::assertStringContainsString("PHP_SAPI === 'cli'", $code);
        foreach (['/\bfn\s*\(/', '/\?\?/', '/\bmatch\s*\(/', '/: string\b|: \?\w+|: int\b/'] as $pattern) {
            self::assertSame(0, preg_match($pattern, (string) preg_replace('#/\*.*?\*/#s', '', $code)), $pattern);
        }
    }
}
