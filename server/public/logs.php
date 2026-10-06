<?php

/**
 * GET /logs.php. Read-only, token-protected view of search_logs (M21): recent
 * queries, only zero-result ones, or the slowest (App\LogsPage). Reachable
 * directly or via the front controller. UI text is English.
 */

declare(strict_types=1);

use App\Db;
use App\LogsPage;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $config = require dirname(__DIR__) . '/bootstrap.php';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

$notice = static function (int $status, string $title, string $form = ''): void {
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Search logs</title></head><body>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $form . '</body></html>';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $notice(405, 'Method not allowed');
    return;
}

$configuredToken = (string) $config['logs']['token'];
if ($configuredToken === '') {
    $notice(503, 'Logs page disabled: set SEARCH_LOGS_TOKEN');
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
    $page = new LogsPage(
        $db->pdo(),
        (string) $config['db']['search_logs_table'],
        (int) $config['logs']['page_size']
    );
    $data = $page->fetch((string) ($_GET['view'] ?? 'recent'), (int) ($_GET['page'] ?? 1));
} catch (Throwable) {
    $notice(500, 'Internal error');
    return;
}

// The token stays out of links unless it arrived in the URL (no header to resend).
echo LogsPage::render($data, $fromUrl !== '' && $provided === $fromUrl ? $fromUrl : '');
