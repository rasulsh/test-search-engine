<?php

/**
 * The admin panel (M39): one entry file, routed with `?p=`. Persian RTL, username + password
 * login, its own session, CSRF on every POST, HTTPS only. Pages: status, ops, aliases (M41), synonyms,
 * tester, analytics, logs, audit, help. `?p=analytics&format=json` is the dashboard's JSON for a
 * logged-in session.
 * Setup and settings: docs/ADMIN.md.
 */

declare(strict_types=1);

use App\AdminAuth;
use App\AdminAliases;
use App\AdminAliasViews;
use App\AdminOps;
use App\AdminOpsViews;
use App\AdminPage;
use App\AdminStatus;
use App\AdminStrings;
use App\AdminViews;
use App\Analytics;
use App\AnalyticsPage;
use App\Audit;
use App\Config;
use App\ConfigDoctor;
use App\Db;
use App\LogsPage;
use App\Paging;
use App\ProductDetails;
use App\Reload;
use App\SearchController;
use App\Synonyms;

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

    $pages = ['status', 'ops', 'aliases', 'synonyms', 'tester', 'analytics', 'logs', 'audit', 'help'];
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
    if (!in_array($page, $pages, true) || ($method !== 'GET' && !in_array($page, ['ops', 'aliases'], true))) {
        $unknown = !in_array($page, $pages, true);
        $refuse($unknown ? 404 : 405, $fa['errors'][$unknown ? 'not_found' : 'method']);
        return;
    }
    $aliasPost = null;
    if ($method === 'POST' && $page === 'aliases') {
        $aliasOps = new AdminAliases($config, $pdo, $audit, $ip);
        $action = (string) ($_POST['action'] ?? '');
        $mode = ['alias_save' => 'group', 'alias_delete' => 'delete', 'alias_raw' => 'raw'][$action] ?? null;
        if ($action === 'alias_restore') {
            $outcome = $aliasOps->restore((string) ($_POST['backup'] ?? ''), (string) ($_POST['version'] ?? ''));
            $tab = 'history';
        } elseif ($mode !== null) {
            $outcome = $aliasOps->save($mode, $_POST);
            $tab = $mode === 'raw' ? 'advanced' : 'groups';
        } else {
            $refuse(400, $fa['errors']['method']);
            return;
        }
        // The page, page size and search of the groups table come back with the form (hidden fields).
        $listState = array_filter([
            'q' => trim((string) ($_POST['q'] ?? '')), 'per' => (string) ($_POST['per'] ?? ''),
            'page' => (string) ($_POST['page'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
        if (in_array($outcome['status'], ['saved', 'restored'], true)) {
            $redirect(http_build_query(['p' => 'aliases', 'tab' => $tab, 'saved' => $outcome['status']]
                + ($tab === 'groups' ? $listState : [])));
            return;
        }
        // Not saved: show the same page again with what was typed and what is wrong with it.
        $aliasPost = [
            'tab' => $tab,
            'result' => $outcome,
            'form' => ['version' => (string) ($_POST['version'] ?? '')] + ($mode === 'group'
                ? ['index' => (string) ($_POST['index'] ?? ''), 'terms' => (string) ($_POST['terms'] ?? '')]
                : []),
            'raw_text' => $mode === 'raw' ? (string) ($_POST['json'] ?? '') : null,
            'list' => $listState,
        ];
    } elseif ($method === 'POST') {
        // The action runs to the end whatever the browser does, and must not hold the session lock meanwhile.
        session_write_close();
        $ops = new AdminOps($config, $pdo, $audit, $ip);
        $loadStaging = ($_POST['load_staging'] ?? '') === '1';
        $result = match ((string) ($_POST['action'] ?? '')) {
            'validate' => $ops->validate($loadStaging),
            'apply' => $ops->apply($loadStaging),
            'rollback' => $ops->rollback(),
            'flush_cache' => $ops->flushCache(),
            'vps_reload' => $ops->vpsReload(),
            default => null,
        };
        if ($result === null) {
            $refuse(400, $fa['errors']['method']);
            return;
        }
        $redirect('p=ops&done=' . (int) $result['id']);
        return;
    }

    $tail = '';
    $css = '';
    switch ($page) {
        case 'status':
            $status = new AdminStatus($config, $pdo, $audit, Config::overrides(Config::file()));
            $body = AdminViews::status($status->collect());
            break;
        case 'ops':
            $probe = new AdminStatus($config, $pdo, $audit, Config::overrides(Config::file()));
            $body = AdminOpsViews::page([
                'reload' => (new Reload($pdo, $config))->describe(),
                'vps' => $probe->vps(),
                'redis' => $probe->redis(),
                'doctor' => ConfigDoctor::check(Config::overrides(Config::file())),
                'recent' => $audit->latest(AdminOps::ACTIONS, 8),
                'done' => (int) ($_GET['done'] ?? 0),
                'csrf' => $csrf,
                'prefix' => (string) $config['redis']['prefix'],
            ]);
            break;
        case 'aliases':
            $aliasOps ??= new AdminAliases($config, $pdo, $audit, $ip);
            $state = $aliasOps->state();
            if (($_GET['download'] ?? '') === '1') {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="aliases.json"');
                echo $state['raw'] !== '' ? $state['raw'] : App\AliasStore::encode($state['groups']);
                return;
            }
            $tab = (string) ($aliasPost['tab'] ?? $_GET['tab'] ?? 'groups');
            $edit = $_GET['edit'] ?? null;
            $data = [
                'tab' => $tab, 'csrf' => $csrf, 'state' => $state,
                'list' => $aliasPost['list'] ?? [
                    'q' => (string) ($_GET['q'] ?? ''), 'per' => $_GET['per'] ?? null,
                    'page' => (int) ($_GET['page'] ?? 1),
                ],
                'edit' => $edit === null ? null : (string) $edit, 'prefill' => (string) ($_GET['prefill'] ?? ''),
                'result' => $aliasPost['result'] ?? null, 'form' => $aliasPost['form'] ?? null,
                'raw_text' => $aliasPost['raw_text'] ?? null,
                'saved' => in_array($_GET['saved'] ?? '', ['saved', 'restored'], true) ? $_GET['saved'] : '',
                'writable' => is_dir($aliasOps->store()->directory()) ? is_writable($aliasOps->store()->directory())
                    : is_writable(dirname($aliasOps->store()->directory())),
                'counts' => null, 'backups' => [], 'diff' => null,
            ];
            if (isset($_GET['count']) && isset($state['groups'][(int) $_GET['count']])) {
                $counter = $aliasOps->counts();
                $index = (int) $_GET['count'];
                $data['counts'] = ['index' => $index, 'cap' => $counter->cap(), 'rows' => array_map(
                    static fn (string $term): array => ['term' => $term, 'count' => $counter->count($term)],
                    $state['groups'][$index]
                )];
            }
            if ($tab === 'history') {
                $data['backups'] = $aliasOps->store()->backups();
                $name = (string) ($_GET['diff'] ?? '');
                $content = $name === '' ? null : $aliasOps->store()->backupContent($name);
                if ($content !== null) {
                    $data['diff'] = ['name' => $name] + AdminAliasViews::groupDiff(
                        Synonyms::parseGroups($content) ?? [],
                        $state['groups']
                    );
                }
            }
            $body = AdminAliasViews::aliases($data);
            break;
        case 'synonyms':
            $path = $config['paths']['data'] . '/' . Synonyms::SYNONYMS_FILE;
            $synonymGroups = is_file($path) ? Synonyms::parseGroups((string) file_get_contents($path)) : null;
            $body = AdminAliasViews::synonyms([
                'term' => (string) ($_GET['term'] ?? ''), 'filter' => (string) ($_GET['filter'] ?? ''),
                'groups' => (new AdminAliases($config, $pdo, $audit, $ip))->store()->read()['groups'],
                'synonyms' => $synonymGroups,
            ]);
            break;
        case 'tester':
            $q = trim((string) ($_GET['q'] ?? ''));
            $view = ['q' => $q, 'asked' => isset($_GET['q'])];
            if ($q !== '') {
                try {
                    $found = SearchController::fromConfig($pdo, $config, null, null, false)
                        ->search(['q' => $q, 'limit' => 10], true);
                    $products = new ProductDetails($pdo, (string) $config['db']['products_table']);
                    $details = $products->fetch($found['product_ids']);
                    $view['result'] = $found;
                    $view['titles'] = array_column($details, 'title', 'id');
                } catch (Throwable $e) {
                    error_log('admin tester: ' . $e->getMessage());
                    $view['error'] = true;
                }
            }
            $body = AdminAliasViews::tester($view);
            break;
        case 'audit':
            $body = AdminViews::audit($audit->recent((int) ($_GET['page'] ?? 1)));
            break;
        case 'logs':
            $logs = new LogsPage(
                $pdo,
                (string) $config['db']['search_logs_table'],
                (int) $config['logs']['page_size'],
                (int) $config['logs']['scan_limit']
            );
            $found = $logs->fetch(
                (string) ($_GET['view'] ?? 'recent'),
                (int) ($_GET['page'] ?? 1),
                (string) ($_GET['sort'] ?? ''),
                (string) ($_GET['dir'] ?? ''),
                (string) ($_GET['q'] ?? ''),
                isset($_GET['per']) ? (int) $_GET['per'] : null
            );
            $body = AdminViews::logs($found + [
                'default_per' => $logs->pageSize(), 'sizes' => Paging::sizes($logs->pageSize()),
            ]);
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
