<?php

/**
 * GET /analytics.php. Read-only, token-protected aggregates over search_logs
 * (M31): most searched and zero-result queries, cache / tier / latency figures
 * and a volume trend (App\Analytics). Same pattern and the same token as
 * logs.php (`logs.token`). `?window=24h|7d|30d|90d|all` (default 7d);
 * `?format=json` returns the same data as JSON. UI text is English.
 */

declare(strict_types=1);

use App\Analytics;
use App\AnalyticsPage;
use App\Db;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $appBase = require __DIR__ . '/app_base.php';
    $config = require $appBase . '/bootstrap.php';
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

$notice = static function (int $status, string $title, string $form = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Search analytics</title></head><body>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $form . '</body></html>';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $notice(405, 'Method not allowed');
    return;
}

$configuredToken = (string) $config['logs']['token'];
if ($configuredToken === '') {
    $notice(503, 'Analytics page disabled: set SEARCH_LOGS_TOKEN');
    return;
}

$fromUrl = (string) ($_GET['token'] ?? '');
$provided = (string) ($_SERVER['HTTP_X_LOGS_TOKEN'] ?? '') ?: $fromUrl;
if (!hash_equals($configuredToken, $provided)) {
    $notice(
        401,
        'Unauthorized',
        '<form method="get"><label>Token <input type="password" name="token" autofocus></label> '
        . '<button>Open</button></form>'
    );
    return;
}

try {
    $db = new Db($config['db']);
    $report = (new Analytics($db->pdo(), (string) $config['db']['search_logs_table']))
        ->report((string) ($_GET['window'] ?? ''));
} catch (Throwable) {
    $notice(500, 'Internal error');
    return;
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return;
}

header('Content-Type: text/html; charset=utf-8');
// The token stays out of links unless it arrived in the URL (no header to resend).
echo AnalyticsPage::render($report, $fromUrl !== '' && $provided === $fromUrl ? $fromUrl : '');
