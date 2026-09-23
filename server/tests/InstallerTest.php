<?php

declare(strict_types=1);

namespace App\Tests;

use App\Installer;
use App\InstallerException;
use App\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Installer logic that needs no database: refusal, validation, and the
 * config.php it renders from the real config.example.php.
 */
final class InstallerTest extends TestCase
{
    private string $serverDir;

    protected function setUp(): void
    {
        $this->serverDir = self::makeServerDir();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->serverDir);
    }

    /** A server directory as extracted from a release: the template, no config.php. */
    public static function makeServerDir(): string
    {
        $dir = sys_get_temp_dir() . '/installer_' . uniqid('', true);
        mkdir($dir, 0777, true);
        copy(dirname(__DIR__) . '/config.example.php', $dir . '/config.example.php');

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $path = $dir . '/' . $item;
                is_dir($path) && !is_link($path) ? self::removeDir($path) : unlink($path);
            }
        }
        rmdir($dir);
    }

    /** @return array<string, string> */
    public static function validInput(array $overrides = []): array
    {
        return array_merge([
            'db_host' => 'localhost',
            'db_port' => '',
            'db_name' => 'cpuser_search',
            'db_user' => 'cpuser_search',
            'db_password' => 'p\'a\\ss$word {${x}} ?>',
            'reload_token' => str_repeat('ab12', 16),
            'model_name' => 'intfloat/multilingual-e5-small',
            'model_dim' => '384',
            'normalization_version' => '2',
            'store_base' => 'https://shop.example.com/',
            'image_base' => 'https://shop.example.com/image/',
            'semantic_min_score' => '0.75',
            'title_weight' => '12',
            'desc_weight' => '0.5',
            'spec_weight' => '6',
            'phrase_bonus' => '4.5',
        ], $overrides);
    }

    /**
     * Evaluate a config file in a clean PHP process (no SEARCH_* environment,
     * which would override the file's values) and return what it yields. App\
     * classes the file names (Normalizer::VERSION) load from $srcDir.
     *
     * @return array<string, mixed>
     */
    public static function evaluateConfig(string $path, ?string $srcDir = null): array
    {
        $env = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                'spl_autoload_register(fn ($c) => require $argv[2] . "/" . substr($c, 4) . ".php");'
                . 'echo json_encode(require $argv[1]);',
                $path,
                $srcDir ?? dirname(__DIR__) . '/src',
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
        $config = json_decode($out, true);
        self::assertIsArray($config, "config did not evaluate: {$err}{$out}");

        return $config;
    }

    public function testRefusesWhenConfigExistsAndLeavesItUntouched(): void
    {
        file_put_contents($this->serverDir . '/config.php', "<?php return ['mine' => true];\n");
        $installer = new Installer($this->serverDir, '/nonexistent/schema.sql');

        self::assertTrue($installer->isInstalled());
        try {
            $installer->install(self::validInput());
            self::fail('install() must refuse when config.php exists');
        } catch (InstallerException $e) {
            self::assertSame('already_installed', $e->reason());
        }
        try {
            $installer->writeConfig($installer->validate(self::validInput()));
            self::fail('writeConfig() must never overwrite config.php');
        } catch (InstallerException $e) {
            self::assertSame('already_installed', $e->reason());
        }
        self::assertSame("<?php return ['mine' => true];\n", file_get_contents($this->serverDir . '/config.php'));
    }

    public function testWritesConfigFromValues(): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        $input = self::validInput(['db_port' => '3307']);
        $installer->writeConfig($installer->validate($input));

        $config = self::evaluateConfig($this->serverDir . '/config.php');

        self::assertSame('mysql:host=localhost;port=3307;dbname=cpuser_search;charset=utf8mb4', $config['db']['dsn']);
        self::assertSame('cpuser_search', $config['db']['user']);
        // Quotes, backslashes, dollar signs and a PHP close tag survive verbatim.
        self::assertSame($input['db_password'], $config['db']['password']);
        self::assertSame($input['reload_token'], $config['reload']['token']);
        self::assertSame('intfloat/multilingual-e5-small', $config['model']['name']);
        self::assertSame(384, $config['model']['dim']);
        self::assertSame(2, $config['model']['normalization_version']);
        self::assertSame('https://shop.example.com/', $config['storefront']['store_base']);
        self::assertSame('https://shop.example.com/image/', $config['storefront']['image_base']);
        self::assertEqualsWithDelta(0.75, $config['search']['semantic_min_score'], 1e-9);
        self::assertEqualsWithDelta(12.0, $config['search']['title_weight'], 1e-9);
        self::assertEqualsWithDelta(0.5, $config['search']['desc_weight'], 1e-9);
        self::assertEqualsWithDelta(6.0, $config['search']['spec_weight'], 1e-9);
        self::assertEqualsWithDelta(4.5, $config['search']['phrase_bonus'], 1e-9);
        // Build-time only: not asked for, not written to the server's config.
        self::assertArrayNotHasKey('build', $config);
        // Everything the form does not ask for keeps the template default.
        self::assertSame(60, $config['search']['rrf_k']);
        self::assertSame('products', $config['db']['products_table']);
        self::assertSame($this->serverDir . '/data_incoming', $config['paths']['data_incoming']);
    }

    public function testRenderedConfigStillLetsTheEnvironmentOverride(): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        $php = $installer->renderConfig($installer->validate(self::validInput()));

        self::assertStringContainsString("getenv('SEARCH_DB_USER') ?: 'cpuser_search'", $php);
        self::assertStringContainsString("\$setting('SEARCH_TITLE_WEIGHT', '12.0')", $php);
        self::assertStringContainsString("(int) (getenv('SEARCH_MODEL_DIM') ?: 384)", $php);
        // validInput() pins version 2, which differs from the code's: a literal.
        self::assertStringContainsString("(int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: 2)", $php);
        self::assertStringContainsString('Written by public/install.php', $php);
    }

    public function testDefaultsComeFromTheTemplateWithAFreshToken(): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        $first = $installer->defaults();

        self::assertSame('intfloat/multilingual-e5-small', $first['model_name']);
        self::assertSame('384', $first['model_dim']);
        self::assertSame((string) Normalizer::VERSION, $first['normalization_version']);
        self::assertSame('0.82', $first['semantic_min_score']);
        self::assertSame('10.0', $first['title_weight']);
        self::assertSame('1.0', $first['desc_weight']);
        self::assertSame('6.0', $first['spec_weight']);
        self::assertSame('5.0', $first['phrase_bonus']);
        self::assertArrayNotHasKey('desc_index_chars', $first);
        self::assertSame('', $first['db_password']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['reload_token']);
        self::assertNotSame($first['reload_token'], $installer->defaults()['reload_token']);
        // The defaults themselves pass validation.
        $installer->validate(array_merge($first, ['db_name' => 'x', 'db_user' => 'x']));
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function invalidInputs(): array
    {
        return [
            'empty db name' => [['db_name' => ''], 'db_name', 'required'],
            'dsn injection in host' => [['db_host' => 'h;dbname=other'], 'db_host', 'invalid'],
            'dsn injection in name' => [['db_name' => 'a;host=evil'], 'db_name', 'invalid'],
            'port out of range' => [['db_port' => '70000'], 'db_port', 'invalid'],
            'empty user' => [['db_user' => ''], 'db_user', 'required'],
            'short token' => [['reload_token' => 'short'], 'reload_token', 'invalid'],
            'token with space' => [['reload_token' => str_repeat('a', 40) . ' b'], 'reload_token', 'invalid'],
            'bad model name' => [['model_name' => "x'y"], 'model_name', 'invalid'],
            'zero dim' => [['model_dim' => '0'], 'model_dim', 'invalid'],
            'fractional dim' => [['model_dim' => '3.5'], 'model_dim', 'invalid'],
            'score above 1' => [['semantic_min_score' => '1.5'], 'semantic_min_score', 'invalid'],
            'negative weight' => [['title_weight' => '-1'], 'title_weight', 'invalid'],
            'non-numeric weight' => [['phrase_bonus' => 'abc'], 'phrase_bonus', 'invalid'],
            'non-http store base' => [['store_base' => 'javascript:alert(1)'], 'store_base', 'invalid'],
            'relative image base' => [['image_base' => 'image/'], 'image_base', 'invalid'],
        ];
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputAndWritesNothing(array $overrides, string $field, string $problem): void
    {
        $installer = new Installer($this->serverDir, '/unused');
        try {
            $installer->install(self::validInput($overrides));
            self::fail('invalid input must be rejected');
        } catch (InstallerException $e) {
            self::assertSame('invalid_input', $e->reason());
            self::assertSame($problem, $e->details()[$field] ?? null);
        }
        self::assertFileDoesNotExist($this->serverDir . '/config.php');
    }

    public function testFoldsPersianDigitsAndAllowsEmptyStorefrontBases(): void
    {
        $values = (new Installer($this->serverDir, '/unused'))->validate(self::validInput([
            'model_dim' => '۳۸۴',
            'semantic_min_score' => '۰٫۸',
            'store_base' => '',
            'image_base' => '',
        ]));

        self::assertSame('384', $values['model_dim']);
        self::assertSame('0.8', $values['semantic_min_score']);
        self::assertSame('', $values['store_base']);
    }

    public function testStagedBundleForAnotherModelIsRejectedBeforeAnythingIsWritten(): void
    {
        mkdir($this->serverDir . '/data_incoming');
        file_put_contents($this->serverDir . '/data_incoming/meta.json', (string) json_encode([
            'model' => 'intfloat/multilingual-e5-base', 'dim' => 768, 'normalization_version' => 2,
        ]));
        try {
            (new Installer($this->serverDir, '/unused'))->install(self::validInput());
            self::fail('an incompatible staged bundle must be rejected');
        } catch (InstallerException $e) {
            self::assertSame('bundle_mismatch', $e->reason());
            self::assertSame('intfloat/multilingual-e5-base', $e->details()['bundle']['model_name']);
        }
        self::assertFileDoesNotExist($this->serverDir . '/config.php');
    }

    public function testTemplateDriftIsDetected(): void
    {
        $template = $this->serverDir . '/config.example.php';
        file_put_contents(
            $template,
            str_replace("'SEARCH_PHRASE_BONUS'", "'SEARCH_PHRASE_BOOST'", (string) file_get_contents($template))
        );
        $installer = new Installer($this->serverDir, '/unused');

        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('template_mismatch');
        $installer->renderConfig($installer->validate(self::validInput()));
    }
}
