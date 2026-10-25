<?php

/**
 * GET /analytics.php. Read-only, token-protected aggregates over search_logs
 * (M31, dashboard M35): most searched and zero-result queries, cache / tier /
 * latency figures, trends, a weekday x hour heatmap, query lengths and top
 * brands / categories (App\Analytics). Same pattern and the same token as
 * logs.php (`logs.token`). `?window=24h|7d|30d|90d|all` (default 7d);
 * `?compare=1` adds the previous window's headline figures (JSON);
 * `?format=json` returns the same data as JSON (the dashboard polls it every
 * `logs.refresh_seconds`). The HTML is a shell drawn client-side without any
 * third-party request. UI text is Persian (App\AnalyticsStrings), RTL, in self-hosted Vazirmatn
 * (public/fonts/vazirmatn); `?format=json` is unchanged by the presentation.
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
$fa = App\AnalyticsStrings::fa();

$notice = static function (int $status, string $title, string $form = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($status);
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . $fa['title']
        . '</title></head><body>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $form . '</body></html>';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $notice(405, $fa['errors']['method']);
    return;
}

$configuredToken = (string) $config['logs']['token'];
if ($configuredToken === '') {
    $notice(503, $fa['errors']['disabled']);
    return;
}

$fromUrl = (string) ($_GET['token'] ?? '');
$provided = (string) ($_SERVER['HTTP_X_LOGS_TOKEN'] ?? '') ?: $fromUrl;
if (!hash_equals($configuredToken, $provided)) {
    $notice(
        401,
        $fa['errors']['unauthorized'],
        '<form method="get"><label>' . $fa['errors']['token']
        . ' <input type="password" name="token" autofocus></label> <button>' . $fa['errors']['open'] . '</button></form>'
    );
    return;
}

try {
    $db = new Db($config['db']);
    $report = (new Analytics(
        $db->pdo(),
        (string) $config['db']['search_logs_table'],
        (string) $config['db']['products_table'],
        (string) $config['db']['search_events_table']
    ))->report((string) ($_GET['window'] ?? ''), 20, in_array($_GET['compare'] ?? '', ['1', 'true'], true));
    $report['refresh_seconds'] = max(0, (int) $config['logs']['refresh_seconds']);
} catch (Throwable) {
    $notice(500, $fa['errors']['internal']);
    return;
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return;
}

// Only this page runs script, and only its own nonce'd block; it may fetch this origin's JSON.
$nonce = base64_encode(random_bytes(16));
header(
    "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; font-src 'self'; "
    . "script-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; form-action 'self'"
);
header('Content-Type: text/html; charset=utf-8');

// search_logs.ts is written by the database (DEFAULT CURRENT_TIMESTAMP) in its own session zone, which
// nothing in the app sets; show that zone's current offset beside the dates, and shift nothing.
$dbClock = ['zone' => '', 'offset_minutes' => null];
try {
    $row = $db->pdo()->query(
        'SELECT @@session.time_zone AS zone, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes'
    )->fetch(PDO::FETCH_ASSOC);
    $dbClock = ['zone' => (string) $row['zone'], 'offset_minutes' => (int) $row['offset_minutes']];
} catch (Throwable) {
    // the dates are then labelled as "database clock, zone unknown"
}
// The fonts sit next to this script, so the page finds them from wherever the app is mounted.
$mount = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
echo AnalyticsPage::render($report, $nonce, ['db_clock' => $dbClock, 'fonts' => $mount . '/fonts/vazirmatn/']);
