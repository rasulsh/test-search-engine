<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * The write action of the admin panel's VPS page: "Probe now". It makes the same GET /health call as
 * tools/vps-probe.php, records it in vps_calls (kind=probe) and leaves one audit row. The page's reads are
 * {@see VpsCallReport}.
 */
final class AdminVps
{
    /**
     * @param array<string, mixed> $config the loaded server config
     * @param VpsClient|null $vps replaces the client built from $config['vps'] (tests)
     */
    public function __construct(
        private readonly array $config,
        private readonly PDO $pdo,
        private readonly Audit $audit,
        private readonly string $ip,
        private readonly ?VpsClient $vps = null
    ) {
    }

    /** @return array{ok: bool, outcome: string, status: int, duration_ms: ?int, error: string} */
    public function probe(): array
    {
        $result = self::probeWith($this->vps ?? VpsClient::fromConfig($this->config['vps']), $this->pdo, $this->config);
        $this->audit->write(
            $this->ip,
            Audit::VPS_PROBE,
            $result['outcome'] . ($result['duration_ms'] === null ? '' : ' ' . $result['duration_ms'] . ' ms')
        );

        return $result;
    }

    /**
     * One recorded probe; shared with tools/vps-probe.php. A deployment without a VPS records
     * `not_configured` instead of calling anything.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, outcome: string, status: int, duration_ms: ?int, error: string}
     */
    public static function probeWith(?VpsClient $client, PDO $pdo, array $config): array
    {
        $log = new VpsCallLog($pdo, (int) $config['vps']['log_retention_days']);
        if ($client === null) {
            $log->record(['kind' => 'probe', 'outcome' => 'not_configured', 'error' => 'vps.url is empty']);

            return ['ok' => false, 'outcome' => 'not_configured', 'status' => 0, 'duration_ms' => null, 'error' => ''];
        }

        return $client->recordTo($log)->probe();
    }
}
