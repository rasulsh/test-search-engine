<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * The write actions of the admin panel's operations page (M40). The reload and the rollback are
 * {@see Reload}'s own code (the same path POST /reload takes, with the same lock), never a copy. Each
 * action runs to the end even if the browser goes away (ignore_user_abort, no time limit), measures
 * how long it took, and leaves one audit row holding its outcome as JSON, which is also what the
 * page shows afterwards: the result survives a closed tab.
 */
final class AdminOps
{
    /** The audit actions this class writes, newest of them are listed on the page. */
    public const ACTIONS = [
        Audit::RELOAD_VALIDATE, Audit::RELOAD_APPLY, Audit::ROLLBACK, Audit::CACHE_FLUSH, Audit::VPS_RELOAD,
        Audit::RELOAD_API,
    ];

    /**
     * @param array<string, mixed> $config the loaded server config
     * @param Cache|null $cache replaces the result cache built from $config['redis'] (tests)
     * @param VpsClient|null $vps replaces the client built from $config['vps'] (tests)
     */
    public function __construct(
        private readonly array $config,
        private readonly PDO $pdo,
        private readonly Audit $audit,
        private readonly string $ip,
        private readonly ?Cache $cache = null,
        private readonly ?VpsClient $vps = null
    ) {
    }

    /** Checks the staged bundle exactly as an apply would, and changes nothing. */
    public function validate(bool $loadStaging): array
    {
        return $this->run(Audit::RELOAD_VALIDATE, fn (): array => $this->reload()->validate($loadStaging));
    }

    /** Swaps the staged bundle in (loading products.load.sql into the staging table first when asked). */
    public function apply(bool $loadStaging): array
    {
        return $this->run(Audit::RELOAD_APPLY, fn (): array => $this->reload()->run($loadStaging));
    }

    public function rollback(): array
    {
        return $this->run(Audit::ROLLBACK, fn (): array => $this->reload()->rollback());
    }

    /** Deletes the result cache's own keys (its prefix), nothing else. */
    public function flushCache(): array
    {
        return $this->run(Audit::CACHE_FLUSH, function (): array {
            // Scanning a big keyspace outlasts the 100 ms a normal cache lookup is given.
            $slow = ['redis' => ['timeout_ms' => max((int) $this->config['redis']['timeout_ms'], 5000)]];
            $cache = $this->cache ?? Cache::fromConfig(array_replace_recursive($this->config, $slow));
            if ($cache === null) {
                return ['ok' => false, 'reason' => 'redis_off'];
            }
            $removed = $cache->purge();

            return $removed === null
                ? ['ok' => false, 'reason' => 'redis_unreachable']
                : ['ok' => true, 'removed' => $removed];
        });
    }

    /** Asks the VPS to load the vectors that were uploaded to it (the upload itself stays outside the panel). */
    public function vpsReload(): array
    {
        return $this->run(Audit::VPS_RELOAD, function (): array {
            $client = $this->vps ?? VpsClient::fromConfig($this->config['vps']);
            if ($client === null) {
                return ['ok' => false, 'reason' => 'vps_off'];
            }
            $answer = $client->reload();

            return [
                'ok' => $answer['ok'],
                'reason' => $answer['ok'] ? null : $answer['error'],
                'status' => $answer['status'],
                'count' => $answer['count'],
                'built_at' => $answer['built_at'],
            ];
        });
    }

    private function reload(): Reload
    {
        return new Reload($this->pdo, $this->config, $this->cache);
    }

    /**
     * @param callable(): array<string, mixed> $work returns the outcome fields (`ok`, `reason`, ...)
     * @return array<string, mixed> the outcome plus `id` (its audit row), `action` and `elapsed` seconds
     */
    private function run(string $action, callable $work): array
    {
        // A closed tab or a proxy timeout must not stop a reload half-way.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $started = microtime(true);
        try {
            $outcome = $work();
        } catch (ReloadException $e) {
            $outcome = ['ok' => false, 'reason' => $e->reason()] + self::scalars($e->details());
        } catch (Throwable $e) {
            error_log('admin: ' . $action . ' failed: ' . $e->getMessage());
            $outcome = ['ok' => false, 'reason' => 'internal'];
        }
        $outcome['elapsed'] = round(microtime(true) - $started, 1);
        $outcome['action'] = $action;
        $outcome['id'] = $this->audit->write(
            $this->ip,
            $action,
            (string) json_encode(
                self::forAudit($outcome),
                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR
            )
        );

        return $outcome;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, scalar|null>
     */
    private static function scalars(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v === null || is_scalar($v));
    }

    /**
     * The audit detail: short keys, no nulls, nothing a reader could not show.
     *
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function forAudit(array $outcome): array
    {
        unset($outcome['action'], $outcome['id'], $outcome['error'], $outcome['path']);
        $outcome['s'] = $outcome['elapsed'];
        unset($outcome['elapsed']);
        if (isset($outcome['reason'])) {
            $outcome['reason'] = mb_substr((string) $outcome['reason'], 0, 120); // the detail column is 512 characters
        }

        return array_filter($outcome, static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
