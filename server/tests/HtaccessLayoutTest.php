<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The shipped .htaccess files: the safety net around a misplaced tree, and the
 * layout rule that public/ stays a direct child of the app base. The Apache
 * behavior itself is checked by hand (docs/DEPLOY.md, go-live checklist).
 */
final class HtaccessLayoutTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function deniedDirectories(): array
    {
        return ['app tree' => ['.'], 'data' => ['data'], 'data_incoming' => ['data_incoming']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedDirectories')]
    public function testDirectoryDeniesEverything(string $dir): void
    {
        $file = dirname(__DIR__) . '/' . $dir . '/.htaccess';

        self::assertFileExists($file);
        self::assertMatchesRegularExpression('/^Require all denied$/m', (string) file_get_contents($file));
    }

    public function testPublicReopensItselfAndDeniesDotfilesWithTheLogsHeaders(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');

        self::assertMatchesRegularExpression('/^Require all granted$/m', $htaccess, 'overrides the parent deny');
        self::assertStringContainsString('<FilesMatch "^\.">', $htaccess);
        foreach (['X-Robots-Tag', 'X-Frame-Options "DENY"', 'Referrer-Policy'] as $header) {
            self::assertStringContainsString($header, $htaccess);
        }
        self::assertStringContainsString('RewriteRule ^(search|health|reload|logs)/?$ index.php [L]', $htaccess);
    }

    public function testEveryEntryPointResolvesTheAppBaseThroughAppBase(): void
    {
        foreach (['index', 'search', 'health', 'reload', 'logs', 'install'] as $name) {
            $php = (string) file_get_contents(dirname(__DIR__) . "/public/{$name}.php");
            self::assertStringContainsString("__DIR__ . '/app_base.php'", $php, "{$name}.php");
            self::assertStringNotContainsString("dirname(__DIR__) . '/bootstrap.php'", $php, "{$name}.php");
        }
    }
}
