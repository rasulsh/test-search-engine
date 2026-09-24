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
 */
final class VpsClient
{
    public const PATH = '/search-vectors';

    /** @var callable(string, list<string>, string, int): array{status: int, body: string}|string */
    private $transport;
    private string $failure = '';

    /**
     * @param null|callable(string, list<string>, string, int): (array{status: int, body: string}|string) $transport
     *        (url, headers, body, timeoutMs) -> response, or an error string. Tests inject one; the default is curl.
     */
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeoutMs,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /**
     * Null when no VPS URL is configured (keyword-only deployment).
     *
     * @param array<string, mixed> $vps the config's `vps` section
     */
    public static function fromConfig(array $vps): ?self
    {
        $url = trim((string) ($vps['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        return new self($url, (string) ($vps['token'] ?? ''), (int) ($vps['timeout_ms'] ?? 300));
    }

    /**
     * Nearest products to the query, best first, all scoring >= $minScore.
     *
     * @return list<array{product_id: int, score: float}>|null null on any failure
     */
    public function search(string $query, int $limit, float $minScore): ?array
    {
        $this->failure = '';
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
        $response = ($this->transport)(
            rtrim($this->url, '/') . self::PATH,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token],
            $body,
            max(1, $this->timeoutMs)
        );
        if (is_string($response)) {
            return $this->fail($response);
        }
        if ($response['status'] !== 200) {
            return $this->fail('http_' . $response['status']);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return $this->fail('malformed_response');
        }
        $results = [];
        foreach ($data['results'] as $row) {
            $id = is_array($row) ? ($row['product_id'] ?? null) : null;
            $score = is_array($row) ? ($row['score'] ?? null) : null;
            if (!is_int($id) || !(is_float($score) || is_int($score)) || !is_finite((float) $score)) {
                return $this->fail('malformed_response');
            }
            // Re-applied here so the floor holds even against a VPS that ignores min_score.
            if ((float) $score >= $minScore) {
                $results[] = ['product_id' => $id, 'score' => (float) $score];
            }
        }
        usort($results, static fn (array $a, array $b): int =>
            [$b['score'], $a['product_id']] <=> [$a['score'], $b['product_id']]);

        return $results;
    }

    /** Why the last search() returned null ('' after a success). */
    public function failure(): string
    {
        return $this->failure;
    }

    /** @return null */
    private function fail(string $reason): ?array
    {
        $this->failure = $reason;

        return null;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}|string
     */
    private static function curl(string $url, array $headers, string $body, int $timeoutMs): array|string
    {
        if (!function_exists('curl_init')) {
            return 'curl_unavailable';
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return 'curl_init_failed';
        }
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // Sub-second timeouts need signals off (otherwise libcurl rounds up to 1 s).
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errno = curl_errno($handle);

            return $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable: ' . curl_error($handle);
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => (string) $responseBody];
    }
}
