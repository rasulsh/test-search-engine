<?php

declare(strict_types=1);

namespace App\Tests;

use App\Installer;
use App\Normalizer;

/**
 * M15.1: a normalization rules bump ships as new code plus a bundle stamped by
 * the new pipeline, and the config.php written at install time must accept it
 * with no edit. Simulated end to end: install with the current code, then
 * "upgrade" a copy of the server whose Normalizer::VERSION is one higher (as a
 * release extraction does, keeping config.php) and reload in a separate PHP
 * process, since a class constant cannot change inside this one.
 */
final class NormalizationVersionBumpTest extends DatabaseTestCase
{
    private const COUNT = 2;

    private string $serverDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        $this->serverDir = InstallerTest::makeServerDir();
        $src = dirname(__DIR__);
        copy($src . '/bootstrap.php', $this->serverDir . '/bootstrap.php');
        mkdir($this->serverDir . '/src');
        foreach (glob($src . '/src/*.php') ?: [] as $file) {
            copy($file, $this->serverDir . '/src/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        InstallerTest::removeDir($this->serverDir);
        $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
    }

    public function testReloadAcceptsABumpedVersionWithNoConfigEdit(): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        $defaults = $installer->defaults();
        self::assertSame((string) Normalizer::VERSION, $defaults['normalization_version']);
        $installer->writeConfig($installer->validate(
            array_merge($defaults, ['db_name' => 'search', 'db_user' => 'search'])
        ));
        $config = (string) file_get_contents($this->serverDir . '/config.php');
        self::assertStringContainsString(
            "(int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: \\App\\Normalizer::VERSION)",
            $config
        );

        $bumped = Normalizer::VERSION + 1;
        $this->bumpCodeVersion($bumped);

        // A bundle from before the bump is now refused: config follows the code.
        $this->stageBundle(Normalizer::VERSION);
        $old = $this->reloadInChildProcess();
        self::assertSame($bumped, $old['config_version']);
        self::assertSame('normalization_version_mismatch', $old['reason'] ?? null);
        self::assertSame($bumped, $old['details']['expected'] ?? null);

        // The bundle the new pipeline builds is accepted and swapped in.
        $this->stageBundle($bumped);
        $new = $this->reloadInChildProcess();
        self::assertTrue($new['ok'] ?? false, (string) json_encode($new));
        self::assertSame(self::COUNT, $new['count']);
        $meta = json_decode((string) file_get_contents($this->serverDir . '/data/meta.json'), true);
        self::assertSame($bumped, $meta['normalization_version']);
        self::assertSame(self::COUNT, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function testAPinnedVersionIsWrittenAsALiteral(): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        $pin = (string) (Normalizer::VERSION + 5);
        $installer->writeConfig($installer->validate(array_merge(
            $installer->defaults(),
            ['db_name' => 'search', 'db_user' => 'search', 'normalization_version' => $pin]
        )));

        $config = (string) file_get_contents($this->serverDir . '/config.php');
        self::assertStringContainsString("(int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: {$pin})", $config);
        $this->bumpCodeVersion(Normalizer::VERSION + 1);
        self::assertSame(
            (int) $pin,
            InstallerTest::evaluateConfig($this->serverDir . '/config.php', $this->serverDir . '/src')
                ['model']['normalization_version']
        );
    }

    private function bumpCodeVersion(int $version): void
    {
        $path = $this->serverDir . '/src/Normalizer.php';
        $php = (string) preg_replace(
            '/public const VERSION = \d+;/',
            "public const VERSION = {$version};",
            (string) file_get_contents($path),
            -1,
            $replaced
        );
        self::assertSame(1, $replaced, 'Normalizer::VERSION declaration not found');
        file_put_contents($path, $php);
    }

    /** A consistent bundle in data_incoming plus its staging table. */
    private function stageBundle(int $normalizationVersion): void
    {
        $dim = (int) InstallerTest::evaluateConfig($this->serverDir . '/config.php')['model']['dim'];
        $dir = $this->serverDir . '/data_incoming';
        is_dir($dir) || mkdir($dir);
        $bin = pack('g*', ...array_fill(0, self::COUNT * $dim, 0.5));
        file_put_contents($dir . '/vectors.bin', $bin);
        file_put_contents($dir . '/vectors.idx', "4001\n4002\n");
        file_put_contents($dir . '/meta.json', (string) json_encode([
            'model' => 'intfloat/multilingual-e5-small',
            'revision' => 'main',
            'dim' => $dim,
            'normalization_version' => $normalizationVersion,
            'count' => self::COUNT,
            'built_at' => '2026-01-01T00:00:00+00:00',
            'checksum' => hash('sha256', $bin),
        ]));

        $this->pdo->exec('DROP TABLE IF EXISTS products_new');
        $this->pdo->exec('CREATE TABLE products_new LIKE products');
        $this->pdo->exec(
            "INSERT INTO products_new (product_id, title, description, normalized_title, normalized_desc)
             VALUES (4001, 't', 'd', 't', 'd'), (4002, 't', 'd', 't', 'd')"
        );
    }

    /**
     * Run POST /reload's core (bootstrap + Reload) against the upgraded copy,
     * with no SEARCH_* environment so only config.php decides.
     *
     * @return array<string, mixed>
     */
    private function reloadInChildProcess(): array
    {
        $script = <<<'PHP'
            $config = require $argv[1] . '/bootstrap.php';
            $pdo = new PDO($argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            try {
                $result = (new App\Reload($pdo, $config))->run();
            } catch (App\ReloadException $e) {
                $result = ['reason' => $e->reason(), 'details' => $e->details()];
            }
            echo json_encode($result + ['config_version' => $config['model']['normalization_version']]);
            PHP;
        $env = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [
                PHP_BINARY, '-r', $script, $this->serverDir,
                (string) getenv('SEARCH_TEST_DB_DSN'),
                getenv('SEARCH_TEST_DB_USER') ?: '',
                getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $result = json_decode($out, true);
        self::assertIsArray($result, "reload did not run: {$err}{$out}");

        return $result;
    }
}
