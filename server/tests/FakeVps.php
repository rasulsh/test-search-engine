<?php

declare(strict_types=1);

namespace App\Tests;

use App\VpsClient;

/**
 * Stand-in for the VPS vector service's POST /search-vectors
 * (vps/search_vectors/app.py): Bearer token, then cosine of the query's vector
 * against every product vector, >= min_score, best first (ties by id), cut to
 * limit. Queries map to vectors by exact text ("*" for any other text); an
 * unmapped query matches nothing. Used in-process (client()) and behind
 * `php -S` (mock_vps.php).
 *
 * Scenario keys: products {id: vector}, queries {text: vector}, token, and the
 * failure knobs status (+ body), malformed, sleep_ms.
 */
final class FakeVps
{
    public const TOKEN = 'fake-vps-token-0123456789';

    /**
     * @param array<string, mixed> $scenario
     * @return array{status: int, body: string}
     */
    public static function respond(array $scenario, string $authorization, string $body): array
    {
        if (($scenario['sleep_ms'] ?? 0) > 0) {
            usleep((int) $scenario['sleep_ms'] * 1000);
        }
        if ($authorization !== 'Bearer ' . ($scenario['token'] ?? self::TOKEN)) {
            return ['status' => 401, 'body' => '{"detail":"missing or wrong token"}'];
        }
        if (isset($scenario['status'])) {
            return ['status' => (int) $scenario['status'], 'body' => (string) ($scenario['body'] ?? '{}')];
        }
        if (($scenario['malformed'] ?? false) === true) {
            return ['status' => 200, 'body' => '{"results": "not a list"}'];
        }

        $request = json_decode($body, true);
        $query = $scenario['queries'][$request['q']] ?? $scenario['queries']['*'] ?? null;
        $results = [];
        foreach ($query === null ? [] : $scenario['products'] ?? [] as $id => $vector) {
            $score = self::cosine($query, $vector);
            if ($score >= (float) ($request['min_score'] ?? -1.0)) {
                $results[] = ['product_id' => (int) $id, 'score' => round($score, 6)];
            }
        }
        usort($results, static fn (array $a, array $b): int =>
            [$b['score'], $a['product_id']] <=> [$a['score'], $b['product_id']]);
        $results = array_slice($results, 0, (int) ($request['limit'] ?? 100));

        return ['status' => 200, 'body' => (string) json_encode(['results' => $results, 'model' => 'fake'])];
    }

    /**
     * GET /health (open) and POST /reload (token): scenario keys `health` / `reload` =>
     * ['status' => int, 'body' => array|string] replace the healthy defaults.
     *
     * @param array<string, mixed> $scenario
     * @return array{status: int, body: string}
     */
    public static function admin(array $scenario, string $path, string $authorization): array
    {
        $key = $path === '/health' ? 'health' : 'reload';
        if ($key === 'reload' && $authorization !== 'Bearer ' . ($scenario['token'] ?? self::TOKEN)) {
            return ['status' => 401, 'body' => '{"detail":"missing or wrong token"}'];
        }
        $default = $key === 'health'
            ? ['ok' => true, 'model' => 'BAAI/bge-m3', 'dim' => 1024, 'count' => 24000,
                'built_at' => '2026-10-20T09:00:00Z']
            : ['ok' => true, 'count' => 24000, 'model' => 'BAAI/bge-m3', 'built_at' => '2026-10-20T09:00:00Z'];
        $set = $scenario[$key] ?? ['status' => 200, 'body' => $default];
        $body = $set['body'] ?? $default;

        return [
            'status' => (int) ($set['status'] ?? 200),
            'body' => is_string($body) ? $body : (string) json_encode($body),
        ];
    }

    /**
     * A client answered in-process by respond(); each call's request body is
     * appended to $calls.
     *
     * @param array<string, mixed> $scenario
     * @param list<array<string, mixed>> $calls
     */
    public static function client(array $scenario, array &$calls = [], string $token = self::TOKEN): VpsClient
    {
        $transport = static function (string $url, array $headers, string $body, int $ms) use ($scenario, &$calls) {
            $calls[] = ['url' => $url, 'timeout_ms' => $ms] + (array) json_decode($body, true);
            $authorization = '';
            foreach ($headers as $header) {
                if (str_starts_with($header, 'Authorization: ')) {
                    $authorization = substr($header, strlen('Authorization: '));
                }
            }

            return self::respond($scenario, $authorization, $body);
        };

        return new VpsClient('http://vps.test', $token, 300, $transport);
    }

    /**
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * ($b[$i] ?? 0.0);
            $na += $value * $value;
            $nb += ($b[$i] ?? 0.0) ** 2;
        }

        return $na > 0 && $nb > 0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
