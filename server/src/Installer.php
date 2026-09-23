<?php

declare(strict_types=1);

namespace App;

use PDO;
use PhpToken;
use Throwable;

/**
 * First-deploy installer behind public/install.php (M14).
 *
 * Order matters: everything that can fail on bad input (validation, bundle
 * compatibility, DB connection, schema) runs BEFORE config.php is written, so
 * a failed attempt leaves nothing behind and the form can simply be resubmitted.
 * config.php is created exclusively and never overwritten; once it exists the
 * installer refuses to do anything. Only the initial catalog load runs after it
 * (the same Reload path as reload.php?load=1), and its failure is reported, not
 * retried here.
 */
final class Installer
{
    public const CONFIG_FILE = 'config.php';
    public const TEMPLATE_FILE = 'config.example.php';

    /** Form field => environment variable whose default it replaces in the template. */
    private const TEMPLATE_KEYS = [
        'db_user'               => 'SEARCH_DB_USER',
        'db_password'           => 'SEARCH_DB_PASSWORD',
        'reload_token'          => 'SEARCH_RELOAD_TOKEN',
        'model_name'            => 'SEARCH_MODEL',
        'model_dim'             => 'SEARCH_MODEL_DIM',
        'normalization_version' => 'SEARCH_NORMALIZATION_VERSION',
        'store_base'            => 'SEARCH_STORE_BASE',
        'image_base'            => 'SEARCH_IMAGE_BASE',
        'semantic_min_score'    => 'SEARCH_SEMANTIC_MIN_SCORE',
        'title_weight'          => 'SEARCH_TITLE_WEIGHT',
        'desc_weight'           => 'SEARCH_DESC_WEIGHT',
        'spec_weight'           => 'SEARCH_SPEC_WEIGHT',
        'phrase_bonus'          => 'SEARCH_PHRASE_BONUS',
    ];

    /**
     * Template default of SEARCH_NORMALIZATION_VERSION: the code's own version.
     * Kept in config.php when the form keeps it, so a later rules bump needs no
     * config edit; any other value is written as a literal pin.
     */
    private const VERSION_DEFAULT = '\App\Normalizer::VERSION';

    /** Numeric fields: [type, min, max]. */
    private const NUMBERS = [
        'model_dim'             => ['int', 1, 8192],
        'normalization_version' => ['int', 1, 1000],
        'semantic_min_score'    => ['float', -1, 1],
        'title_weight'          => ['float', 0, 1000],
        'desc_weight'           => ['float', 0, 1000],
        'spec_weight'           => ['float', 0, 1000],
        'phrase_bonus'          => ['float', 0, 1000],
    ];

    private string $configPath;
    private string $templatePath;

    public function __construct(string $serverDir, private readonly string $schemaPath)
    {
        $this->configPath = rtrim($serverDir, '/') . '/' . self::CONFIG_FILE;
        $this->templatePath = rtrim($serverDir, '/') . '/' . self::TEMPLATE_FILE;
    }

    public function isInstalled(): bool
    {
        return file_exists($this->configPath) || is_link($this->configPath);
    }

    /**
     * Form values pre-filled from the template's defaults, plus a fresh reload
     * token. The DB password is never pre-filled.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $template = $this->template();
        $values = [
            'db_host' => 'localhost',
            'db_port' => '',
            'db_name' => '',
            'db_password' => '',
            'reload_token' => bin2hex(random_bytes(32)),
        ];
        foreach (self::TEMPLATE_KEYS as $field => $env) {
            $values[$field] ??= self::literalValue(self::findDefault($template, $env)[2]);
        }

        return $values;
    }

    /**
     * Run the whole first deploy.
     *
     * @param array<string, mixed> $input raw form values
     * @return array{status: string, count?: int, reason?: string, details?: array<string, mixed>}
     *         status "loaded" (catalog live), "no_bundle" (nothing staged yet), or
     *         "load_failed" (config written, catalog not loaded: use the manual fallback)
     * @throws InstallerException when nothing was written (bad input, DB, schema) or
     *         config.php already exists
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            throw new InstallerException('already_installed');
        }
        $values = $this->validate($input);
        $this->assertBundleCompatible($values);
        $pdo = $this->connect($values);
        $this->createSchema($pdo);
        $this->writeConfig($values);

        $config = $this->loadConfig($this->configPath);
        if (!is_file(rtrim((string) $config['paths']['data_incoming'], '/') . '/meta.json')) {
            return ['status' => 'no_bundle'];
        }
        try {
            $result = (new Reload((new Db($config['db']))->pdo(), $config))->run(true);
        } catch (ReloadException $e) {
            return ['status' => 'load_failed', 'reason' => $e->reason(), 'details' => $e->details()];
        } catch (Throwable $e) {
            return ['status' => 'load_failed', 'reason' => 'error', 'details' => ['error' => $e->getMessage()]];
        }

        return ['status' => 'loaded', 'count' => $result['count']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> trimmed, canonical values
     * @throws InstallerException invalid_input with details [field => problem]
     */
    public function validate(array $input): array
    {
        $values = [];
        foreach (array_merge(['db_host', 'db_port', 'db_name'], array_keys(self::TEMPLATE_KEYS)) as $field) {
            $raw = $input[$field] ?? '';
            $raw = is_string($raw) ? $raw : '';
            // The password is taken verbatim: surrounding spaces may be part of it.
            $values[$field] = $field === 'db_password' ? $raw : trim($raw);
        }

        $errors = [];
        $require = static function (bool $ok, string $field, string $problem) use (&$errors): void {
            if (!$ok && !isset($errors[$field])) {
                $errors[$field] = $problem;
            }
        };

        $require($values['db_host'] !== '', 'db_host', 'required');
        $require(preg_match('/^[A-Za-z0-9.\-]{1,255}$/', $values['db_host']) === 1, 'db_host', 'invalid');
        $port = self::foldDigits($values['db_port']);
        $values['db_port'] = $port;
        $require(
            $port === '' || (ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535),
            'db_port',
            'invalid'
        );
        $require($values['db_name'] !== '', 'db_name', 'required');
        $require(preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $values['db_name']) === 1, 'db_name', 'invalid');
        $require($values['db_user'] !== '', 'db_user', 'required');
        $require(preg_match('/^[^\x00-\x1F\x7F]{1,128}$/u', $values['db_user']) === 1, 'db_user', 'invalid');
        $require(preg_match('/^[^\x00-\x1F\x7F]{0,256}$/u', $values['db_password']) === 1, 'db_password', 'invalid');
        // Sent as an HTTP header on reload: printable ASCII, no spaces.
        $require(preg_match('/^[\x21-\x7E]{32,256}$/', $values['reload_token']) === 1, 'reload_token', 'invalid');
        $require(
            preg_match('#^[A-Za-z0-9._\-/]{1,200}$#', $values['model_name']) === 1,
            'model_name',
            'invalid'
        );
        foreach (['store_base', 'image_base'] as $field) {
            $url = $values[$field];
            $require(
                $url === '' || (filter_var($url, FILTER_VALIDATE_URL) !== false
                    && preg_match('#^https?://#i', $url) === 1),
                $field,
                'invalid'
            );
        }
        foreach (self::NUMBERS as $field => [$type, $min, $max]) {
            $number = self::foldDigits($values[$field]);
            $valid = $type === 'int'
                ? preg_match('/^\d+$/', $number) === 1
                : preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $number) === 1;
            $valid = $valid && (float) $number >= $min && (float) $number <= $max;
            $require($valid, $field, 'invalid');
            if ($valid) {
                $values[$field] = $type === 'int'
                    ? (string) (int) $number
                    : var_export((float) $number, true);
            }
        }

        if ($errors !== []) {
            throw new InstallerException('invalid_input', $errors);
        }

        return $values;
    }

    /** @param array<string, string> $values */
    public static function dsn(array $values): string
    {
        return 'mysql:host=' . $values['db_host']
            . ($values['db_port'] !== '' ? ';port=' . $values['db_port'] : '')
            . ';dbname=' . $values['db_name'] . ';charset=utf8mb4';
    }

    /**
     * @param array<string, string> $values
     * @throws InstallerException db_connect_failed (the message never carries the password)
     */
    public function connect(array $values): PDO
    {
        try {
            $db = new Db([
                'dsn' => self::dsn($values),
                'user' => $values['db_user'],
                'password' => $values['db_password'],
            ]);
            $db->pdo()->query('SELECT 1');

            return $db->pdo();
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($values['db_password'] !== '') {
                $message = str_replace($values['db_password'], '***', $message);
            }
            throw new InstallerException('db_connect_failed', ['error' => $message]);
        }
    }

    /** @throws InstallerException schema_failed */
    public function createSchema(PDO $pdo): void
    {
        $sql = is_file($this->schemaPath) ? file_get_contents($this->schemaPath) : false;
        if ($sql === false) {
            throw new InstallerException('schema_failed', ['error' => 'schema.sql not found']);
        }
        // Statements end with ";" at the end of a line; comment lines hold none.
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        try {
            foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }
        } catch (Throwable $e) {
            throw new InstallerException('schema_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The template with each installer-managed default replaced by the given
     * value. Environment variables still override the file, as documented.
     *
     * @param array<string, string> $values validated values
     */
    public function renderConfig(array $values): string
    {
        $php = $this->template();
        $replacements = ['SEARCH_DB_DSN' => self::dsn($values)];
        foreach (self::TEMPLATE_KEYS as $field => $env) {
            $replacements[$env] = $values[$field];
        }
        foreach ($replacements as $env => $value) {
            [$offset, $length, $literal] = self::findDefault($php, $env);
            if ($literal === self::VERSION_DEFAULT && $value === (string) Normalizer::VERSION) {
                continue;
            }
            $new = str_starts_with($literal, "'") ? var_export($value, true) : $value;
            $php = substr_replace($php, $new, $offset, $length);
        }
        $php = preg_replace(
            '/^declare\(strict_types=1\);$/m',
            "declare(strict_types=1);\n\n// Written by public/install.php on " . gmdate('Y-m-d H:i') . ' UTC. Holds the'
            . "\n// DB password and reload token: keep it private (outside public/).",
            $php,
            1
        ) ?? $php;

        try {
            PhpToken::tokenize($php, TOKEN_PARSE);
        } catch (Throwable $e) {
            throw new InstallerException('config_invalid', ['error' => $e->getMessage()]);
        }

        return $php;
    }

    /**
     * Create config.php exclusively: an existing file is never touched.
     *
     * @param array<string, string> $values validated values
     * @throws InstallerException already_installed | config_write_failed
     */
    public function writeConfig(array $values): void
    {
        $php = $this->renderConfig($values);
        $handle = @fopen($this->configPath, 'x');
        if ($handle === false) {
            throw new InstallerException($this->isInstalled() ? 'already_installed' : 'config_write_failed');
        }
        $written = fwrite($handle, $php);
        fclose($handle);
        if ($written !== strlen($php)) {
            @unlink($this->configPath);
            throw new InstallerException('config_write_failed');
        }
        @chmod($this->configPath, 0640);
    }

    /**
     * Reject a staged bundle the new config would refuse on reload, before any
     * file is written (the same model/dim/normalization check Reload makes).
     *
     * @param array<string, string> $values validated values
     */
    private function assertBundleCompatible(array $values): void
    {
        $incoming = rtrim((string) $this->loadConfig($this->templatePath)['paths']['data_incoming'], '/');
        $meta = is_file($incoming . '/meta.json')
            ? json_decode((string) file_get_contents($incoming . '/meta.json'), true)
            : null;
        if (!is_array($meta)) {
            return;
        }
        $actual = [
            'model_name' => (string) ($meta['model'] ?? ''),
            'model_dim' => (string) (int) ($meta['dim'] ?? 0),
            'normalization_version' => (string) (int) ($meta['normalization_version'] ?? 0),
        ];
        foreach ($actual as $field => $value) {
            if ($values[$field] !== $value) {
                throw new InstallerException('bundle_mismatch', ['field' => $field, 'bundle' => $actual]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadConfig(string $path): array
    {
        $config = (static fn (string $file): mixed => require $file)($path);
        if (!is_array($config)) {
            throw new InstallerException('config_invalid', ['error' => basename($path) . ' returned no array']);
        }

        return $config;
    }

    private function template(): string
    {
        $php = is_file($this->templatePath) ? file_get_contents($this->templatePath) : false;
        if ($php === false) {
            throw new InstallerException('template_missing');
        }

        return $php;
    }

    /**
     * Locate the default literal for $env in the template: the right side of
     * `getenv('X') ?: <literal>` or the second argument of `$setting('X', '<literal>')`
     * (a quoted string, a number, or VERSION_DEFAULT).
     *
     * @return array{int, int, string} byte offset, length, literal source
     */
    private static function findDefault(string $php, string $env): array
    {
        $name = preg_quote($env, '/');
        $literal = "('(?:[^'\\\\]|\\\\.)*'|-?\\d+(?:\\.\\d+)?|" . preg_quote(self::VERSION_DEFAULT, '/') . ')';
        $pattern = "/(?:getenv\\('{$name}'\\)\\s*\\?:\\s*|\\\$setting\\('{$name}',\\s*){$literal}/";
        if (preg_match_all($pattern, $php, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new InstallerException('template_mismatch', ['key' => $env]);
        }
        [$literalSource, $offset] = $matches[1][0];

        return [$offset, strlen($literalSource), $literalSource];
    }

    private static function literalValue(string $literal): string
    {
        if ($literal === self::VERSION_DEFAULT) {
            return (string) Normalizer::VERSION;
        }
        if (!str_starts_with($literal, "'")) {
            return $literal;
        }

        return strtr(substr($literal, 1, -1), ["\\'" => "'", '\\\\' => '\\']);
    }

    /** Persian and Arabic-Indic digits typed on a Persian keyboard -> ASCII. */
    private static function foldDigits(string $value): string
    {
        static $map = null;
        $map ??= array_combine(
            [...mb_str_split('۰۱۲۳۴۵۶۷۸۹'), ...mb_str_split('٠١٢٣٤٥٦٧٨٩'), '٫'],
            [...str_split('0123456789'), ...str_split('0123456789'), '.']
        );

        return strtr($value, $map);
    }
}
