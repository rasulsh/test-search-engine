<?php

/**
 * The admin panel (M39): one entry file, routed with `?p=`. Persian RTL, username + password
 * login, its own session, CSRF on every POST, HTTPS only. Pages: status, analytics, logs, audit,
 * help. `?p=analytics&format=json` is the dashboard's JSON for a logged-in session.
 * Setup and settings: docs/ADMIN.md.
 */

declare(strict_types=1);

use App\AdminAuth;
use App\AdminPage;
use App\AdminStatus;
use App\AdminStrings;
use App\AdminViews;
use App\Analytics;
use App\AnalyticsPage;
use App\Audit;
use App\Config;
use App\Db;
use App\LogsPage;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $appBase = require __DIR__ . '/app_base.php';
    $config = require $appBase . '/bootstrap.php';
}

$fa = AdminStrings::fa();
$nonce = base64_encode(random_bytes(16));
$mount = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$ctx = ['nonce' => $nonce, 'fonts' => $mount . '/fonts/vazirmatn/', 'csrf' => ''];

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; font-src 'self'; img-src data:; "
    . "script-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);

$respond = static function (int $status, string $html): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
};
$refuse = static fn (int $status, string $message) => $respond(
    $status,
    AdminPage::bare($fa['title'], AdminViews::notice($message), $ctx)
);
$redirect = static function (string $query): void {
    http_response_code(303);
    header('Location: ?' . $query);
};

$admin = $config['admin'];
if (!AdminAuth::configured($admin)) {
    $refuse(503, $fa['errors']['not_configured']);
    return;
}
$auth = new AdminAuth($admin);
if (!$auth->isHttps($_SERVER)) {
    $refuse(403, $fa['errors']['https_required']);
    return;
}
$ip = $auth->clientIp($_SERVER);
if (!$auth->ipAllowed($ip)) {
    $refuse(403, $fa['errors']['forbidden_ip']);
    return;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    $refuse(405, $fa['errors']['method']);
    return;
}

try {
    $pdo = (new Db($config['db']))->pdo();
    $audit = new Audit($pdo, (string) $config['db']['admin_audit_table'], (int) $admin['audit_retention_days']);
    $auth = new AdminAuth($admin, $audit);
    $auth->start($mount);

    $pages = ['status', 'analytics', 'logs', 'audit', 'help'];
    $page = (string) ($_GET['p'] ?? 'status');
    $json = $page === 'analytics' && ($_GET['format'] ?? '') === 'json';
    $state = $auth->check(time(), $json);
    $csrf = $auth->csrfToken();
    $ctx['csrf'] = $csrf;

    if ($state !== 'ok') {
        if ($page === 'login' && $method === 'POST') {
            if (!$auth->csrfValid((string) ($_POST['csrf'] ?? ''))) {
                $form = AdminViews::login($csrf, $fa['errors']['csrf']);
                $respond(403, AdminPage::bare($fa['login']['title'], $form, $ctx));
                return;
            }
            $result = $auth->attemptLogin(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $ip,
                time()
            );
            if ($result === 'ok') {
                $next = (string) ($_GET['next'] ?? '');
                $redirect('p=' . (in_array($next, $pages, true) ? $next : 'status'));
                return;
            }
            $throttled = $result === 'throttled';
            $form = AdminViews::login($csrf, $fa['errors'][$throttled ? 'throttled' : 'invalid']);
            $respond($throttled ? 429 : 401, AdminPage::bare($fa['login']['title'], $form, $ctx));
            return;
        }
        if ($page !== 'login') {
            if ($json) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $reason = ['idle' => 'idle', 'expired' => 'expired'][$state] ?? '';
            $next = in_array($page, $pages, true) ? $page : '';
            $redirect(http_build_query(array_filter(['p' => 'login', 'r' => $reason, 'next' => $next])));
            return;
        }
        $notice = match ((string) ($_GET['r'] ?? '')) {
            'idle' => [$fa['login']['idle'], 'bad'],
            'expired' => [$fa['login']['expired'], 'bad'],
            'out' => [$fa['login']['logged_out'], 'ok'],
            default => ['', 'bad'],
        };
        $respond(200, AdminPage::bare($fa['login']['title'], AdminViews::login($csrf, $notice[0], $notice[1]), $ctx));
        return;
    }

    // Logged in from here on.
    if ($method === 'POST' && !$auth->csrfValid((string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        $refuse(403, $fa['errors']['csrf']);
        return;
    }
    if ($page === 'logout') {
        if ($method !== 'POST') {
            $refuse(405, $fa['errors']['method']);
            return;
        }
        $auth->logout($ip);
        $redirect('p=login&r=out');
        return;
    }
    if ($page === 'login') {
        $redirect('p=status');
        return;
    }
    if ($method !== 'GET' || !in_array($page, $pages, true)) {
        $refuse($method !== 'GET' ? 405 : 404, $fa['errors'][$method !== 'GET' ? 'method' : 'not_found']);
        return;
    }

    $tail = '';
    $css = '';
    switch ($page) {
        case 'status':
            $status = new AdminStatus($config, $pdo, $audit, Config::overrides(Config::file()));
            $body = AdminViews::status($status->collect());
            break;
        case 'audit':
            $body = AdminViews::audit($audit->recent((int) ($_GET['page'] ?? 1)));
            break;
        case 'logs':
            $logs = new LogsPage($pdo, (string) $config['db']['search_logs_table'], (int) $config['logs']['page_size']);
            $body = AdminViews::logs($logs->fetch((string) ($_GET['view'] ?? 'recent'), (int) ($_GET['page'] ?? 1)));
            break;
        case 'help':
            $body = AdminViews::help();
            break;
        default: // analytics
            $report = (new Analytics(
                $pdo,
                (string) $config['db']['search_logs_table'],
                (string) $config['db']['products_table'],
                (string) $config['db']['search_events_table']
            ))->report((string) ($_GET['window'] ?? ''), 20, in_array($_GET['compare'] ?? '', ['1', 'true'], true));
            $report['refresh_seconds'] = max(0, (int) $config['logs']['refresh_seconds']);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                return;
            }
            // search_logs.ts is written by the database in its own session zone, which nothing in the
            // app sets; show that zone's current offset beside the dates and shift nothing.
            $dbClock = ['zone' => '', 'offset_minutes' => null];
            try {
                $row = $pdo->query(
                    'SELECT @@session.time_zone AS zone,'
                    . ' TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes'
                )->fetch(PDO::FETCH_ASSOC);
                $dbClock = ['zone' => (string) $row['zone'], 'offset_minutes' => (int) $row['offset_minutes']];
            } catch (Throwable) {
                // the dates are then labelled as "database clock, zone unknown"
            }
            $body = AdminViews::helpBox($fa['analytics']['help']);
            $tail = '<div class="content">' . AnalyticsPage::body($report, $nonce, ['db_clock' => $dbClock]) . '</div>';
            $css = AnalyticsPage::css();
    }
    $respond(200, AdminPage::shell($page, $body, $ctx + ['tail' => $tail, 'css' => $css]));
} catch (Throwable $e) {
    error_log('admin: ' . $e->getMessage());
    $missingAudit = $e instanceof PDOException && $e->getCode() === '42S02'
        && str_contains($e->getMessage(), (string) $config['db']['admin_audit_table']);
    $refuse(
        $missingAudit ? 503 : 500,
        $fa['errors'][$missingAudit ? 'audit_missing' : 'internal']
    );
}
