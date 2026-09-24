<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * public/install.php over HTTP, served from a copy of the server tree laid out
 * as an extracted release (code + template + db/schema.sql, no config.php).
 */
final class InstallEndpointTest extends DatabaseTestCase
{
    private const DIM = 4;

    private string $serverDir = '';
    /** @var resource|null */
    private $process = null;
    private string $baseUrl = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products, products_new, products_old, search_logs');

        $root = self::repoRoot();
        $this->serverDir = InstallerTest::makeServerDir();
        foreach (['bootstrap.php', 'src', 'public'] as $part) {
            self::copyTree($root . '/server/' . $part, $this->serverDir . '/' . $part);
        }
        self::copyTree($root . '/db/schema.sql', $this->serverDir . '/db/schema.sql');
    }

    /** @param array<string, string> $ini php.ini overrides for the served requests */
    private function startServer(array $ini = []): void
    {
        $flags = [];
        foreach ($ini as $key => $value) {
            array_push($flags, '-d', "{$key}={$value}");
        }
        $port = self::freePort();
        $this->baseUrl = "http://127.0.0.1:{$port}";
        // No SEARCH_* in the server's environment: config.php alone must drive it.
        $env = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [PHP_BINARY, ...$flags, '-S', "127.0.0.1:{$port}", '-t', $this->serverDir . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->serverDir,
            $env
        );
        self::assertIsResource($process);
        $this->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('built-in PHP server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->serverDir !== '') {
            InstallerTest::removeDir($this->serverDir);
            $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), 0777, true);
            copy($from, $to);

            return;
        }
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && $item !== 'client') {
                self::copyTree($from . '/' . $item, $to . '/' . $item);
            }
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        return $port;
    }

    /** @return array{int, string} */
    private function request(string $method, array $form = []): array
    {
        if ($this->process === null) {
            $this->startServer();
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($form),
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $body = (string) file_get_contents($this->baseUrl . '/install.php', false, $context);
        preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        preg_match_all('/(\w+)=([^;]*)/', substr((string) getenv('SEARCH_TEST_DB_DSN'), 6), $m);
        $dsn = array_combine($m[1], $m[2]);

        return InstallerTest::validInput(array_merge([
            'db_host' => $dsn['host'] ?? 'localhost',
            'db_port' => $dsn['port'] ?? '',
            'db_name' => $dsn['dbname'] ?? '',
            'db_user' => (string) getenv('SEARCH_TEST_DB_USER'),
            'db_password' => (string) getenv('SEARCH_TEST_DB_PASSWORD'),
            'model_dim' => (string) self::DIM,
            'reload_token' => 'tok-' . str_repeat('9f', 20),
        ], $overrides));
    }

    private function stageBundle(): void
    {
        $dir = $this->serverDir . '/data_incoming';
        mkdir($dir);
        $bin = pack('g*', ...array_fill(0, 6 * self::DIM, 0.5));
        file_put_contents($dir . '/vectors.bin', $bin);
        file_put_contents($dir . '/vectors.idx', "2001\n2002\n2003\n2004\n2005\n2006\n");
        copy(self::repoRoot() . '/fixtures/products.load.sample.sql', $dir . '/products.load.sql');
        file_put_contents($dir . '/meta.json', (string) json_encode([
            'model' => 'intfloat/multilingual-e5-small', 'dim' => self::DIM,
            'normalization_version' => 2, 'count' => 6, 'checksum' => hash('sha256', $bin),
        ]));
    }

    public function testFormIsRtlPersianWithDefaultsAndAFreshToken(): void
    {
        [$status, $body] = $this->request('GET');

        self::assertSame(200, $status);
        self::assertStringContainsString('<html lang="fa" dir="rtl">', $body);
        self::assertMatchesRegularExpression('/name="reload_token" value="[0-9a-f]{64}"/', $body);
        self::assertStringContainsString('name="model_name" value="intfloat/multilingual-e5-small"', $body);
        self::assertStringContainsString('name="semantic_min_score" value="0.4"', $body);
        self::assertStringContainsString('name="vps_timeout_ms" value="300"', $body);
        // Build-time only (release.py --desc-index-chars): not a server setting.
        self::assertStringNotContainsString('desc_index_chars', $body);
        self::assertStringContainsString('type="password" id="db_password" name="db_password" value=""', $body);
        foreach (
            ['db_host', 'db_name', 'db_user', 'store_base', 'image_base', 'title_weight', 'desc_weight',
            'spec_weight', 'phrase_bonus', 'model_dim', 'normalization_version', 'vps_url', 'vps_token'] as $field
        ) {
            self::assertStringContainsString('name="' . $field . '"', $body);
        }
    }

    public function testBadDatabaseConnectionShowsTheFormAgainWithoutSecrets(): void
    {
        $form = $this->form(['db_password' => 'wrong-pass-7c1d']);
        [$status, $body] = $this->request('POST', $form);

        self::assertSame(422, $status);
        self::assertStringContainsString('اتصال به پایگاه داده برقرار نشد', $body);
        self::assertStringNotContainsString('wrong-pass-7c1d', $body);
        self::assertStringNotContainsString($form['reload_token'], $body);
        self::assertFileDoesNotExist($this->serverDir . '/config.php');
    }

    public function testInstallsLoadsAndThenRefusesToRunAgain(): void
    {
        $this->stageBundle();
        $form = $this->form();

        [$status, $body] = $this->request('POST', $form);

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('نصب کامل شد', $body);
        self::assertStringContainsString('۶ محصول', $body);
        self::assertStringContainsString('install.php', $body); // "delete install.php"
        // Secrets are not printed back (the test DB password may be too short to
        // search for, e.g. "root" is in the CSS; no form field is rendered at all).
        self::assertStringNotContainsString('<input', $body);
        self::assertStringNotContainsString($form['reload_token'], $body);
        self::assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $written = (string) file_get_contents($this->serverDir . '/config.php');

        [$status, $body] = $this->request('GET');
        self::assertSame(403, $status);
        self::assertStringContainsString('سرویس قبلاً نصب شده است', $body);
        self::assertStringNotContainsString('<form', $body);

        [$status] = $this->request('POST', $this->form(['db_user' => 'someone_else']));
        self::assertSame(403, $status);
        self::assertSame($written, file_get_contents($this->serverDir . '/config.php'));
    }

    public function testALoadCutOffByAHostLimitShowsTheManualFallback(): void
    {
        $this->stageBundle();
        // One statement larger than the memory limit: the loader holds a whole
        // statement, so PHP dies with a fatal error mid-load, as on a tight host.
        file_put_contents(
            $this->serverDir . '/data_incoming/products.load.sql',
            "SET NAMES utf8mb4;\nREPLACE INTO `products_new` VALUES ('" . str_repeat('x', 24 * 1024 * 1024) . "');\n"
        );
        $this->startServer(['memory_limit' => '16M']);

        [$status, $body] = $this->request('POST', $this->form());

        self::assertSame(500, $status);
        self::assertStringContainsString('بارگذاری اولیه کامل نشد', $body);
        self::assertStringContainsString('Allowed memory size', $body);
        self::assertStringContainsString('mysql -u DB_USER -p DB_NAME', $body);
        self::assertStringContainsString('/reload.php</pre>', $body);
        self::assertFileExists($this->serverDir . '/config.php');
        self::assertFileExists($this->serverDir . '/data_incoming/meta.json'); // nothing swapped
    }

    public function testExistingConfigIsNeverTouchedEvenIfBroken(): void
    {
        // A config.php that would fatal if loaded: the refusal must not load it.
        file_put_contents($this->serverDir . '/config.php', "<?php syntax error\n");

        [$getStatus, $body] = $this->request('GET');
        [$postStatus] = $this->request('POST', $this->form());

        self::assertSame(403, $getStatus);
        self::assertSame(403, $postStatus);
        self::assertStringContainsString('reload.php', $body); // manual fallback help
        self::assertSame("<?php syntax error\n", file_get_contents($this->serverDir . '/config.php'));
    }
}
