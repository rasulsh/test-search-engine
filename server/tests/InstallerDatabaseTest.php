<?php

declare(strict_types=1);

namespace App\Tests;

use App\Installer;
use App\InstallerException;

/**
 * Installer steps against a real MariaDB/MySQL: connection test, schema
 * creation, and the initial bundle load + atomic swap.
 */
final class InstallerDatabaseTest extends DatabaseTestCase
{
    private const DIM = 4;
    private const LOAD_SAMPLE_IDS = [2001, 2002, 2003, 2004, 2005, 2006];

    private string $serverDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products, products_new, products_old, search_logs');
        $this->serverDir = InstallerTest::makeServerDir();
    }

    protected function tearDown(): void
    {
        if ($this->serverDir !== '') {
            InstallerTest::removeDir($this->serverDir);
            $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        }
    }

    private function installer(): Installer
    {
        return new Installer($this->serverDir, self::repoRoot() . '/db/schema.sql');
    }

    /** Form input pointing at the test database. @return array<string, string> */
    private function input(array $overrides = []): array
    {
        $dsn = (string) getenv('SEARCH_TEST_DB_DSN');
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strlen('mysql:')), $m);
        $parts = array_combine($m[1], $m[2]);

        return InstallerTest::validInput(array_merge([
            'db_host' => $parts['host'] ?? 'localhost',
            'db_port' => $parts['port'] ?? '',
            'db_name' => $parts['dbname'] ?? '',
            'db_user' => (string) getenv('SEARCH_TEST_DB_USER'),
            'db_password' => (string) getenv('SEARCH_TEST_DB_PASSWORD'),
            'model_dim' => (string) self::DIM,
        ], $overrides));
    }

    /** Stage fixtures/products.load.sample.sql as a release would, in data_incoming/. */
    private function stageBundle(): void
    {
        $dir = $this->serverDir . '/data_incoming';
        mkdir($dir);
        $count = count(self::LOAD_SAMPLE_IDS);
        $bin = pack('g*', ...array_fill(0, $count * self::DIM, 0.5));
        file_put_contents($dir . '/vectors.bin', $bin);
        file_put_contents($dir . '/vectors.idx', implode("\n", self::LOAD_SAMPLE_IDS) . "\n");
        copy(self::repoRoot() . '/fixtures/products.load.sample.sql', $dir . '/products.load.sql');
        file_put_contents($dir . '/meta.json', (string) json_encode([
            'model' => 'intfloat/multilingual-e5-small',
            'dim' => self::DIM,
            'normalization_version' => 2,
            'count' => $count,
            'checksum' => hash('sha256', $bin),
        ]));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function testRejectsABadDatabaseConnectionAndWritesNothing(): void
    {
        $input = $this->input(['db_password' => 'definitely-wrong-password-9f8e']);
        try {
            $this->installer()->install($input);
            self::fail('a bad DB connection must be rejected');
        } catch (InstallerException $e) {
            self::assertSame('db_connect_failed', $e->reason());
            self::assertStringNotContainsString('definitely-wrong-password-9f8e', (string) $e->details()['error']);
        }
        self::assertFileDoesNotExist($this->serverDir . '/config.php');
        self::assertFalse($this->tableExists('search_logs'));
    }

    public function testRejectsAnUnknownDatabase(): void
    {
        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('db_connect_failed');
        $this->installer()->install($this->input(['db_name' => 'no_such_db_' . bin2hex(random_bytes(4))]));
    }

    public function testInstallsConfigSchemaAndLoadsTheStagedBundle(): void
    {
        $this->stageBundle();

        $result = $this->installer()->install($this->input());

        self::assertSame(['status' => 'loaded', 'count' => 6], $result);
        $config = InstallerTest::evaluateConfig($this->serverDir . '/config.php');
        self::assertSame((string) getenv('SEARCH_TEST_DB_USER'), $config['db']['user']);
        self::assertTrue($this->tableExists('search_logs'));
        self::assertSame(
            self::LOAD_SAMPLE_IDS,
            array_map('intval', $this->pdo->query('SELECT product_id FROM products ORDER BY product_id')
                ->fetchAll(\PDO::FETCH_COLUMN))
        );
        // Same swap as reload.php?load=1: bundle moved into data/, staging consumed.
        self::assertFileExists($this->serverDir . '/data/vectors.bin');
        self::assertDirectoryDoesNotExist($this->serverDir . '/data_incoming');
        self::assertFalse($this->tableExists('products_new'));
        // The installer then refuses to run again.
        $this->expectExceptionMessage('already_installed');
        $this->installer()->install($this->input());
    }

    public function testWithoutAStagedBundleItStillWritesConfigAndSchema(): void
    {
        self::assertSame(['status' => 'no_bundle'], $this->installer()->install($this->input()));
        self::assertFileExists($this->serverDir . '/config.php');
        self::assertTrue($this->tableExists('products'));
        self::assertTrue($this->tableExists('search_logs'));
    }

    public function testAFailedInitialLoadIsReportedAndSwapsNothing(): void
    {
        $this->stageBundle();
        file_put_contents($this->serverDir . '/data_incoming/products.load.sql', "DROP TABLE `products`;\n");

        $result = $this->installer()->install($this->input());

        self::assertSame('load_failed', $result['status']);
        self::assertSame('unexpected_statement', $result['reason']);
        self::assertFileExists($this->serverDir . '/config.php');
        self::assertTrue($this->tableExists('products'));
        self::assertFileExists($this->serverDir . '/data_incoming/meta.json');
        self::assertDirectoryDoesNotExist($this->serverDir . '/data');
    }
}
