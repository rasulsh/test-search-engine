<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * The read-only facts of the admin status page (M39). Every outbound probe (VPS /health, Redis)
 * has a short timeout and degrades to "unreachable", so the page never hangs when either is
 * down. The config doctor part carries key paths only, never values.
 */
final class AdminStatus
{
    /** @var callable(string, int): (array{status: int, body: string}|null) */
    private $http;

    /**
     * @param array<string, mixed> $config the loaded server config
     * @param array<string, mixed> $overrides config.php's raw overrides, for the doctor
     * @param null|callable(string, int): (array{status: int, body: string}|null) $http (url, timeoutMs) -> response;
     *        null on a transport failure. Tests inject one; the default is curl.
     */
    public function __construct(
        private readonly array $config,
        private readonly PDO $pdo,
        private readonly Audit $audit,
        private readonly array $overrides,
        ?callable $http = null,
        private readonly ?RedisClient $redis = null
    ) {
        $this->http = $http ?? [self::class, 'curlGet'];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $dataDir = rtrim((string) $this->config['paths']['data'], '/');

        return [
            'database' => $this->database(),
            'bundle' => $this->bundle($dataDir),
            'groups' => [
                'aliases' => self::countGroups($dataDir . '/' . Synonyms::ALIASES_FILE),
                'synonyms' => self::countGroups($dataDir . '/' . Synonyms::SYNONYMS_FILE),
            ],
            'vps' => $this->vps(),
            'redis' => $this->redis(),
            'doctor' => ConfigDoctor::check($this->overrides),
            'config' => ConfigReport::rows($this->overrides),
            'last_reload' => $this->lastReload(),
        ];
    }

    /** @return array{ok: bool, products: ?int} */
    private function database(): array
    {
        try {
            $table = Identifier::quote((string) $this->config['db']['products_table']);
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

            return ['ok' => true, 'products' => $count];
        } catch (Throwable) {
            return ['ok' => false, 'products' => null];
        }
    }

    /** @return array<string, mixed> */
    private function bundle(string $dataDir): array
    {
        $path = $dataDir . '/meta.json';
        $meta = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (!is_array($meta)) {
            return ['present' => false];
        }

        return [
            'present' => true,
            'built_at' => $meta['built_at'] ?? null,
            'normalization_version' => $meta['normalization_version'] ?? null,
            'count' => $meta['count'] ?? null,
        ];
    }

    private static function countGroups(string $path): ?int
    {
        $json = is_file($path) ? @file_get_contents($path) : false;
        $groups = $json === false ? null : Synonyms::parseGroups($json);

        return $groups === null ? null : count($groups);
    }

    /** @return array<string, mixed> state: off | ok | unreachable | error */
    public function vps(): array
    {
        $url = trim((string) $this->config['vps']['url']);
        if ($url === '') {
            return ['state' => 'off'];
        }
        $reply = ($this->http)(rtrim($url, '/') . '/health', (int) $this->config['admin']['probe_timeout_ms']);
        if ($reply === null) {
            return ['state' => 'unreachable'];
        }
        $body = json_decode($reply['body'], true);
        if (!is_array($body)) {
            return ['state' => 'error', 'http' => $reply['status']];
        }

        return [
            'state' => $reply['status'] === 200 && ($body['ok'] ?? false) === true ? 'ok' : 'error',
            'http' => $reply['status'],
            'count' => $body['count'] ?? null,
            'model' => $body['model'] ?? null,
            'built_at' => $body['built_at'] ?? null,
        ];
    }

    /** @return array<string, mixed> state: off | ok | unreachable */
    public function redis(): array
    {
        $cfg = $this->config['redis'];
        if (!$cfg['enabled']) {
            return ['state' => 'off'];
        }
        $client = $this->redis ?? new RedisClient(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['auth'],
            (int) $cfg['db'],
            (int) $this->config['admin']['probe_timeout_ms']
        );
        try {
            $client->ping();

            return ['state' => 'ok', 'keys' => $client->countByPrefix((string) $cfg['prefix'])];
        } catch (Throwable) {
            return ['state' => 'unreachable'];
        } finally {
            $client->close();
        }
    }

    private function lastReload(): ?string
    {
        try {
            return $this->audit->lastOf([Audit::RELOAD_API, 'reload']);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{status: int, body: string}|null */
    public static function curlGet(string $url, int $timeoutMs): ?array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return is_string($body) && $status > 0 ? ['status' => $status, 'body' => $body] : null;
    }
}
