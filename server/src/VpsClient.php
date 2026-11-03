<?php

declare(strict_types=1);

namespace App;

/**
 * Server-to-server client for the VPS vector service (vps/README.md):
 * POST {url}/search-vectors {q, limit, min_score} -> [{product_id, score}].
 *
 * Tier 2 is additive, so every failure (not configured, unreachable, timeout,
 * non-200, malformed body) returns null with the reason in failure(); the
 * caller serves keyword-only results. The timeout bounds the whole exchange,
 * connect included, so a dead VPS costs /search at most timeout_ms.
 *
 * Every call (search, reload, probe) can be recorded to vps_calls through {@see recordTo()}: outcome,
 * curl timings, and the VPS's own Server-Timing / X-Vps-Inflight headers when it sends them. The
 * recording happens once, after the response, and can never throw or change a result.
 */
final class VpsClient
{
    public const PATH = '/search-vectors';

    /** @var callable(string, list<string>, string, int, string): array{status: int, body: string}|string */
    private $transport;
    private string $failure = '';
    private ?VpsCallLog $log = null;
    /** @var array<string, mixed>|null the call in progress: kind, request id, wall time, curl timings */
    private ?array $call = null;
    /** @var array<string, mixed>|null what the default curl transport measured on its last call */
    private ?array $net = null;

    /**
     * @param null|callable(string, list<string>, string, int, string): (array{status: int, body: string,
     *        headers?: array<string, string>, timing?: array<string, float>}|string) $transport
     *        (url, headers, body, timeoutMs, method) -> response, or an error string. Tests inject one (extra
     *        arguments may be ignored); the default is curl. `headers` are the response's, names in lower case.
     */
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeoutMs,
        ?callable $transport = null,
        private readonly int $reloadTimeoutMs = 60000
    ) {
        $this->transport = $transport ?? $this->curl(...);
    }

    /**
     * Null when no VPS URL is configured (keyword-only deployment).
     *
     * @param array<string, mixed> $vps the config's `vps` section
     */
    public static function fromConfig(array $vps): ?self
    {
        $url = trim((string) $vps['url']);
        if ($url === '') {
            return null;
        }

        return new self(
            $url,
            (string) $vps['token'],
            (int) $vps['timeout_ms'],
            null,
            (int) $vps['reload_timeout_ms']
        );
    }

    /** Records every later call to $log (vps_calls). Never changes a call's result. */
    public function recordTo(VpsCallLog $log): self
    {
        $this->log = $log;

        return $this;
    }

    /**
     * GET /health (open, no token): the cheapest call, for the probe tool and the panel's "Probe now". Bounded
     * by the same timeout as a search, so its timings compare with the search calls'. Never throws.
     *
     * @return array{ok: bool, outcome: string, status: int, duration_ms: ?int, error: string}
     */
    public function probe(): array
    {
        $response = $this->send('probe', '/health', [], '', max(1, $this->timeoutMs), 'GET');
        if (is_string($response)) {
            return $this->finish('probe', $response, null);
        }
        if ($response['status'] !== 200) {
            return $this->finish('probe', 'http_' . $response['status'], $response);
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['ok'] ?? null) !== true) {
            return $this->finish('probe', 'malformed_response', $response);
        }

        return $this->finish('probe', '', $response);
    }

    /**
     * Asks the VPS to load the vectors it was uploaded (its POST /reload, same bearer token). Not part
     * of /search: used by the admin panel, with its own, longer timeout. Never throws.
     *
     * @return array{ok: bool, status: int, count: ?int, model: ?string, built_at: ?string, error: string}
     *         status 0 = no answer (the reason is in `error`: timeout, unreachable ...)
     */
    public function reload(): array
    {
        $response = $this->send(
            'reload',
            '/reload',
            ['Authorization: Bearer ' . $this->token],
            '',
            max(1, $this->reloadTimeoutMs)
        );
        if (is_string($response)) {
            $this->finish('reload', $response, null);

            return [
                'ok' => false, 'status' => 0, 'count' => null, 'model' => null, 'built_at' => null,
                'error' => $response,
            ];
        }
        $data = json_decode($response['body'], true);
        $data = is_array($data) ? $data : [];
        $ok = $response['status'] === 200 && ($data['ok'] ?? false) === true;
        $reason = $response['status'] === 200 ? 'malformed_response' : 'http_' . $response['status'];
        $this->finish('reload', $ok ? '' : $reason, $response);

        return [
            'ok' => $ok,
            'status' => $response['status'],
            'count' => isset($data['count']) ? (int) $data['count'] : null,
            'model' => isset($data['model']) ? (string) $data['model'] : null,
            'built_at' => isset($data['built_at']) ? (string) $data['built_at'] : null,
            'error' => $ok ? '' : (is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $response['status']),
        ];
    }

    /**
     * Nearest products to the query, best first, all scoring >= $minScore.
     *
     * @return list<array{product_id: int, score: float}>|null null on any failure
     */
    public function search(string $query, int $limit, float $minScore): ?array
    {
        $this->failure = '';
        $this->call = null;
        $body = json_encode(
            ['q' => $query, 'limit' => max(1, $limit), 'min_score' => $minScore],
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if (trim($query) === '') {
            return $this->fail('empty_query');
        }
        if ($body === false) {
            return $this->fail('encode_failed');
        }
        $response = $this->send(
            'search',
            self::PATH,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token],
            $body,
            max(1, $this->timeoutMs),
            'POST',
            $query
        );
        if (is_string($response)) {
            return $this->fail($response);
        }
        if ($response['status'] !== 200) {
            return $this->fail('http_' . $response['status'], $response);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return $this->fail('malformed_response', $response);
        }
        $results = [];
        foreach ($data['results'] as $row) {
            $id = is_array($row) ? ($row['product_id'] ?? null) : null;
            $score = is_array($row) ? ($row['score'] ?? null) : null;
            if (!is_int($id) || !(is_float($score) || is_int($score)) || !is_finite((float) $score)) {
                return $this->fail('malformed_response', $response);
            }
            // Re-applied here so the floor holds even against a VPS that ignores min_score.
            if ((float) $score >= $minScore) {
                $results[] = ['product_id' => $id, 'score' => (float) $score];
            }
        }
        usort($results, static fn (array $a, array $b): int =>
            [$b['score'], $a['product_id']] <=> [$a['score'], $b['product_id']]);
        $this->finish('search', '', $response, count($data['results']));

        return $results;
    }

    /** Why the last search() returned null ('' after a success). */
    public function failure(): string
    {
        return $this->failure;
    }

    /**
     * @param array{status: int, body: string}|null $response
     * @return null
     */
    private function fail(string $reason, ?array $response = null): ?array
    {
        $this->failure = $reason;
        if ($this->call !== null) {
            $this->finish((string) $this->call['kind'], $reason, $response);
        }

        return null;
    }

    /**
     * Sends one request through the transport, tagged with a request id the VPS echoes in its log line, and
     * keeps what is needed to record the call afterwards.
     *
     * @param list<string> $headers
     * @return array{status: int, body: string}|string
     */
    private function send(
        string $kind,
        string $path,
        array $headers,
        string $body,
        int $timeoutMs,
        string $method = 'POST',
        string $query = ''
    ): array|string {
        $id = bin2hex(random_bytes(16));
        $headers[] = 'X-Request-Id: ' . $id;
        $this->net = null;
        $started = hrtime(true);
        $response = ($this->transport)(rtrim($this->url, '/') . $path, $headers, $body, $timeoutMs, $method);
        $this->call = [
            'kind' => $kind, 'id' => $id, 'timeout_ms' => $timeoutMs, 'query' => $query,
            'wall_ms' => (int) round((hrtime(true) - $started) / 1e6), 'net' => $this->net,
        ];

        return $response;
    }

    /**
     * Records the finished call ('' reason = success) and returns the probe-style summary.
     *
     * @param array{status: int, body: string}|null $response
     * @return array{ok: bool, outcome: string, status: int, duration_ms: ?int, error: string}
     */
    private function finish(string $kind, string $reason, ?array $response, ?int $neighbours = null): array
    {
        $outcome = self::outcome($reason);
        $call = $this->call ?? [];
        $net = is_array($call['net'] ?? null) ? $call['net'] : [];
        $total = isset($net['total_ms']) ? (int) $net['total_ms'] : ($call['wall_ms'] ?? null);
        $errno = !empty($net['errno']) ? ' [curl ' . $net['errno'] . ']' : '';
        $error = $reason === '' ? '' : $this->scrub($reason . $errno);
        $summary = [
            'ok' => $outcome === 'ok', 'outcome' => $outcome, 'status' => $response['status'] ?? 0,
            'duration_ms' => $total, 'error' => $error,
        ];
        if ($this->log === null || $call === []) {
            return $summary;
        }
        try {
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $timing = self::serverTiming((string) ($headers['server-timing'] ?? ''));
            $inflight = $headers['x-vps-inflight'] ?? null;
            $this->log->record([
                'kind' => $kind,
                'outcome' => $outcome,
                'http_status' => isset($response['status']) ? $response['status'] : null,
                'duration_ms' => $total,
                'connect_ms' => $net['connect_ms'] ?? null,
                'ttfb_ms' => $net['ttfb_ms'] ?? null,
                'timeout_ms' => $call['timeout_ms'] ?? null,
                'error' => $error,
                'response_bytes' => isset($response['body']) ? strlen($response['body']) : null,
                'neighbours' => $neighbours,
                'request_id' => $call['id'] ?? null,
                'vps_queue_ms' => $timing['queue'] ?? null,
                'vps_embed_ms' => $timing['embed'] ?? null,
                'vps_search_ms' => $timing['search'] ?? null,
                'vps_inflight' => is_string($inflight) && ctype_digit($inflight) ? (int) $inflight : null,
                'normalized_q' => $kind === 'search' ? (string) ($call['query'] ?? '') : null,
            ]);
        } catch (\Throwable) {
            // Recording is evidence, never a reason to fail a call.
        }

        return $summary;
    }

    /** 'timeout', 'unreachable ...', 'http_503', 'malformed_response', '' -> the vps_calls outcome. */
    public static function outcome(string $reason): string
    {
        return match (true) {
            $reason === '' => 'ok',
            $reason === 'timeout' => 'timeout',
            str_starts_with($reason, 'http_') => 'http_error',
            $reason === 'malformed_response' => 'bad_body',
            default => 'unreachable',
        };
    }

    /**
     * "queue;dur=0.4, embed;dur=35.1, search;dur=12.3" -> ['queue' => 0.4, ...]; a malformed entry is skipped.
     *
     * @return array<string, float>
     */
    public static function serverTiming(string $header): array
    {
        $found = [];
        foreach (explode(',', $header) as $part) {
            if (preg_match('/^\s*([a-z]+)\s*;\s*dur=(\d+(?:\.\d+)?)\s*$/i', $part, $m) === 1) {
                $found[strtolower($m[1])] = (float) $m[2];
            }
        }

        return $found;
    }

    /** The reason as stored: the VPS address, host and token never appear in it. */
    private function scrub(string $text): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        $secrets = array_filter([
            rtrim($this->url, '/') => '[vps]', is_string($host) ? $host : '' => '[vps]', $this->token => '[token]',
        ], static fn ($replacement, $needle): bool => $needle !== '', ARRAY_FILTER_USE_BOTH);
        uksort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return trim(preg_replace('/\s+/', ' ', strtr($text, $secrets)) ?? '');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|string
     */
    private function curl(
        string $url,
        array $headers,
        string $body,
        int $timeoutMs,
        string $method = 'POST'
    ): array|string {
        if (!function_exists('curl_init')) {
            return 'curl_unavailable';
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return 'curl_init_failed';
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // Sub-second timeouts need signals off (otherwise libcurl rounds up to 1 s).
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }

                return strlen($line);
            },
        ] + ($method === 'GET' ? [CURLOPT_HTTPGET => true] : [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]));
        $responseBody = curl_exec($handle);
        // A time curl never reached reads 0: stored as NULL, which is how "never connected" shows.
        $ms = static fn (int $info): ?int => ($t = (float) curl_getinfo($handle, $info)) > 0.0
            ? max(1, (int) round($t * 1000)) : null;
        $this->net = [
            'connect_ms' => $ms(CURLINFO_CONNECT_TIME), 'ttfb_ms' => $ms(CURLINFO_STARTTRANSFER_TIME),
            'total_ms' => $ms(CURLINFO_TOTAL_TIME), 'errno' => curl_errno($handle),
        ];
        if ($responseBody === false) {
            return $this->net['errno'] === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable: ' . curl_error($handle);
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}
