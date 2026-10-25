<?php

declare(strict_types=1);

namespace App;

/**
 * HTML bodies of the admin panel's pages (M39). Every dynamic value is escaped here; the Persian
 * text comes from {@see AdminStrings}.
 */
final class AdminViews
{
    private static function e(mixed $value): string
    {
        return AdminPage::esc((string) $value);
    }

    /** Every number the panel prints: Latin digits, "," thousands (1,118). */
    public static function num(int|string|null $n): string
    {
        return is_numeric($n) ? number_format((float) $n, 0, '.', ',') : (string) $n;
    }

    /** A stored timestamp: Gregorian as stored (left to right inside the RTL text) with the Jalali date beside it. */
    public static function ts(mixed $value): string
    {
        return AdminDate::html($value);
    }

    /** @param array{what: string, how: string, effect: string, when: string, risk: string} $help */
    public static function helpBox(array $help): string
    {
        $fa = AdminStrings::fa();
        $html = '<details class="helpbox"><summary>' . self::e($fa['ui']['help_title']) . '</summary><dl>';
        foreach ($fa['help_labels'] as $key => $label) {
            $html .= '<dt>' . self::e($label) . '</dt><dd>' . self::e($help[$key]) . '</dd>';
        }

        return $html . '</dl></details>';
    }

    public static function login(string $csrf, string $message = '', string $messageKind = 'bad'): string
    {
        $t = AdminStrings::fa()['login'];
        $msg = $message === ''
            ? ''
            : '<p class="msg ' . self::e($messageKind) . '" role="alert">' . self::e($message) . '</p>';

        return '<form class="login" method="post" action="?p=login" autocomplete="off"><h1>' . self::e($t['title'])
            . '</h1>' . $msg
            . '<input type="hidden" name="csrf" value="' . self::e($csrf) . '">'
            . '<label for="u">' . self::e($t['username']) . '</label>'
            . '<input id="u" name="username" required autofocus autocomplete="username" dir="ltr">'
            . '<label for="p">' . self::e($t['password']) . '</label>'
            . '<input id="p" name="password" type="password" required autocomplete="current-password" dir="ltr">'
            . '<button class="btn primary">' . self::e($t['submit']) . '</button>'
            . '<p class="note">' . self::e($t['hint']) . '</p></form>';
    }

    /** @param string $message a plain refusal (HTTPS, address, not configured) */
    public static function notice(string $message): string
    {
        return '<p class="msg bad" role="alert">' . self::e($message) . '</p>';
    }

    /** @param array<string, mixed> $s AdminStatus::collect() */
    public static function status(array $s): string
    {
        $t = AdminStrings::fa()['status'];
        $badge = static fn (string $kind, string $text): string => '<span class="badge ' . $kind . '">'
            . self::e($text) . '</span>';
        $row = static fn (string $label, string $value): string => '<dt>' . self::e($label) . '</dt><dd>'
            . $value . '</dd>';
        $ltr = static fn (mixed $value): string => '<bdi class="ltr">' . self::e($value) . '</bdi>';
        $unknown = self::e(AdminStrings::fa()['ui']['unknown']);
        $unreachable = AdminStrings::fa()['errors']['unreachable'];

        $db = $s['database'];
        $cards = '<section class="card"><h2>' . self::e($t['database']) . '</h2><dl class="kv">'
            . $row($t['database'], $db['ok'] ? $badge('ok', $t['database_ok']) : $badge('bad', $t['database_down']))
            . $row($t['products'], $db['products'] === null ? $unknown : self::e(self::num($db['products'])))
            . '</dl></section>';

        $b = $s['bundle'];
        $cards .= '<section class="card"><h2>' . self::e($t['bundle']) . '</h2>';
        if ($b['present']) {
            $cards .= '<dl class="kv">' . $row($t['built_at'], self::ts($b['built_at']))
                . $row($t['norm_version'], self::e(self::num($b['normalization_version'])))
                . $row($t['bundle_count'], self::e(self::num($b['count']))) . '</dl>';
        } else {
            $cards .= '<p>' . $badge('warn', $t['bundle_missing']) . '</p>';
        }
        $cards .= '</section>';

        $g = $s['groups'];
        $count = static fn (?int $n): string => $n === null ? self::e($t['groups_unreadable']) : self::e(self::num($n));
        $cards .= '<section class="card"><h2>' . self::e($t['groups']) . '</h2><dl class="kv">'
            . $row($t['aliases'], $count($g['aliases'])) . $row($t['synonyms'], $count($g['synonyms']))
            . '</dl></section>';

        $v = $s['vps'];
        $cards .= '<section class="card"><h2>' . self::e($t['vps']) . '</h2>';
        if ($v['state'] === 'off') {
            $cards .= '<p>' . $badge('off', $t['vps_off']) . '</p>';
        } elseif ($v['state'] === 'unreachable') {
            $cards .= '<p>' . $badge('bad', $unreachable) . '</p>';
        } else {
            $health = $v['state'] === 'ok' ? $badge('ok', $t['vps_ok']) : $badge('bad', $t['vps_error']);
            $cards .= '<dl class="kv">' . $row($t['vps'], $health)
                . $row($t['vps_count'], $v['count'] === null ? $unknown : self::e(self::num($v['count'])))
                . $row($t['model'], $v['model'] === null ? $unknown : $ltr($v['model']))
                . $row($t['built_at'], self::ts($v['built_at'])) . '</dl>';
        }
        $cards .= '</section>';

        $r = $s['redis'];
        $cards .= '<section class="card"><h2>' . self::e($t['redis']) . '</h2>';
        if ($r['state'] === 'off') {
            $cards .= '<p>' . $badge('off', $t['redis_off']) . '</p>';
        } elseif ($r['state'] === 'unreachable') {
            $cards .= '<p>' . $badge('bad', $unreachable) . '</p>';
        } else {
            $cards .= '<dl class="kv">' . $row($t['redis'], $badge('ok', $t['redis_ok']))
                . $row($t['redis_keys'], self::e(self::num($r['keys']))) . '</dl>';
        }
        $cards .= '</section>';

        $reloaded = $s['last_reload'] === null ? self::e($t['last_reload_none']) : self::ts($s['last_reload']);
        $cards .= '<section class="card"><h2>' . self::e($t['last_reload']) . '</h2><p>' . $reloaded
            . '</p></section>';

        $cards .= '<section class="card"><h2>' . self::e($t['doctor']) . '</h2>' . self::doctorBody($s['doctor'])
            . '<p class="note">' . self::e($t['doctor_note']) . '</p></section>';

        return self::helpBox($t['help']) . '<div class="cards">' . $cards . '</div>' . self::configTable($s['config']);
    }

    /**
     * The config doctor's verdict: counts as badges, then every key path with its message.
     *
     * @param array{errors: list<string>, warnings: list<string>, info: list<string>} $d
     */
    public static function doctorBody(array $d): string
    {
        $t = AdminStrings::fa()['status'];
        $badge = static fn (string $kind, string $text): string => '<span class="badge ' . $kind . '">'
            . self::e($text) . '</span>';
        $errors = $d['errors'] === [] ? ''
            : $badge('bad', $t['doctor_errors'] . ': ' . self::num(count($d['errors']))) . ' ';
        $warnings = $d['warnings'] === [] ? ''
            : $badge('warn', $t['doctor_warnings'] . ': ' . self::num(count($d['warnings'])));
        $html = $errors . $warnings === '' ? '<p>' . $badge('ok', $t['doctor_ok']) . '</p>' : '<p>' . $errors
            . $warnings . '</p>';
        foreach (['errors', 'warnings'] as $kind) {
            if ($d[$kind] !== []) {
                $html .= '<ul class="keys">';
                foreach ($d[$kind] as $line) {
                    $html .= '<li>' . self::e($line) . '</li>';
                }
                $html .= '</ul>';
            }
        }

        return $html;
    }

    /**
     * @param list<array{key: string, value: ?string, state: string, source: string, issues: list<array{level: string,
     *        message: string}>}> $rows
     */
    private static function configTable(array $rows): string
    {
        $t = AdminStrings::fa()['config'];
        $body = '';
        foreach ($rows as $row) {
            $value = match ($row['state']) {
                'set' => '<span class="badge ok">' . self::e($t['secret_set']) . '</span>',
                'empty' => '<span class="badge off">' . self::e($t['secret_empty']) . '</span>',
                'hidden' => '<span class="badge off">' . self::e($t['hidden']) . '</span>',
                default => $row['value'] === ''
                    ? '<span class="note">' . self::e($t['empty']) . '</span>'
                    : '<bdi class="ltr">' . self::e($row['value']) . '</bdi>',
            };
            $notes = '';
            foreach ($row['issues'] as $issue) {
                $kind = $issue['level'] === 'error' ? 'bad' : 'warn';
                $notes .= '<div><span class="badge ' . $kind . '">' . self::e($t[$issue['level']])
                    . '</span> <bdi class="ltr">'
                    . self::e($issue['message']) . '</bdi></div>';
            }
            $fromFile = $row['source'] === 'file';
            $body .= '<tr data-changed="' . ($fromFile || $notes !== '' ? '1' : '0') . '"><td><bdi class="ltr">'
                . self::e($row['key']) . '</bdi></td><td>' . $value . '</td><td class="c">'
                . self::e($fromFile ? $t['source_file'] : $t['source_default']) . '</td><td class="wrap">' . $notes
                . '</td></tr>';
        }

        return '<section class="card cfg"><h2>' . self::e($t['title']) . '</h2><p class="note">'
            . self::e($t['intro']) . '</p>'
            . '<div class="controls"><input type="search" data-filter="cfg-table" placeholder="' . self::e($t['filter'])
            . '" aria-label="' . self::e($t['filter'])
            . '"> <label><input type="checkbox" data-changed-only="cfg-table"> '
            . self::e($t['only_changed'])
            . '</label></div><div class="scroll tall"><table id="cfg-table"><thead><tr><th>'
            . self::e($t['key']) . '</th><th>' . self::e($t['value']) . '</th><th class="c">' . self::e($t['source'])
            . '</th><th>'
            . self::e($t['issues']) . '</th></tr></thead><tbody>' . $body . '</tbody></table></div></section>';
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, from: int, to: int} $data
     */
    public static function audit(array $data): string
    {
        $t = AdminStrings::fa()['audit'];
        $rows = '';
        foreach ($data['rows'] as $row) {
            $label = $t['actions'][$row['action']] ?? $row['action'];
            $rows .= '<tr><td class="c">' . self::ts($row['ts']) . '</td><td class="c"><bdi class="ltr">'
                . self::e($row['ip']) . '</bdi></td><td>' . self::e($label) . '</td><td class="wrap">'
                . self::auditDetail($row) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">' . self::e($t['empty']) . '</td></tr>';
        }
        $pager = self::pager($data, static fn (int $page): string => '?p=audit&amp;page=' . $page);

        $head = '';
        foreach (['time' => 'c', 'ip' => 'c', 'action' => '', 'detail' => ''] as $column => $class) {
            $head .= '<th' . ($class === '' ? '' : ' class="' . $class . '"') . '>' . self::e($t[$column]) . '</th>';
        }

        return self::helpBox($t['help']) . $pager . '<div class="scroll card"><table><thead><tr>' . $head
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager;
    }

    /**
     * One audit row's detail as a sentence. The stored text is JSON (or `count=N` from POST /reload) and
     * must never reach the page raw; a row it does not recognise falls back to the text itself.
     *
     * @param array<string, mixed> $row an `admin_audit` row
     */
    public static function auditDetail(array $row): string
    {
        $t = AdminStrings::fa()['audit']['details'];
        $action = (string) $row['action'];
        $raw = trim((string) $row['detail']);
        if ($raw === '') {
            return self::e($t['none']);
        }
        if ($action === Audit::RELOAD_API && preg_match('/^count=(\d+)$/', $raw, $m) === 1) {
            return self::e(AdminStrings::fill($t['reload_api_count'], self::num($m[1])));
        }
        $detail = json_decode($raw, true);
        if (!is_array($detail)) {
            return '<bdi dir="auto">' . self::e($raw) . '</bdi>';
        }
        if (in_array($action, AdminOps::ACTIONS, true)) {
            [$kind, $message] = AdminOpsViews::message($row);

            $label = AdminStrings::fa()['ops']['recent'][$kind === 'ok' ? 'ok' : 'failed'];

            return '<span class="badge ' . ($kind === 'ok' ? 'ok' : 'bad') . '">' . self::e($label) . '</span> '
                . self::e($message);
        }
        if ($action === Audit::ALIAS_SAVE || $action === Audit::ALIAS_RESTORE) {
            $list = static function (array $names, int $total): string {
                $text = implode(AdminStrings::fa()['ui']['list_sep'], array_map('strval', $names));
                $more = $total - count($names);

                return $names === [] ? '-' : $text . ($more > 0 ? ' (+' . self::num($more) . ')' : '');
            };
            $summary = AdminStrings::fill(
                $t['alias_save'],
                self::num($detail['groups'] ?? 0),
                self::num($detail['terms'] ?? 0),
                $list((array) ($detail['added'] ?? []), (int) ($detail['added_total'] ?? 0)),
                $list((array) ($detail['removed'] ?? []), (int) ($detail['removed_total'] ?? 0))
            );

            if ($action === Audit::ALIAS_RESTORE) {
                $backup = (string) ($detail['backup'] ?? '');
                $summary = AdminStrings::fill($t['alias_restore'], $backup, self::num($detail['groups'] ?? 0))
                    . ' ' . $summary;
            }

            return '<bdi dir="auto">' . self::e($summary) . '</bdi>';
        }

        return '<bdi dir="auto">' . self::e($raw) . '</bdi>';
    }

    /** Columns centered in the logs table (numbers, dates, short codes); the rest start-aligned and may be cut. */
    private const LOG_CUT = ['raw_q', 'normalized_q', 'did_you_mean', 'top_ids', 'customer_id'];
    private const LOG_CENTERED = ['id', 'ts', 'tier', 'had_vector', 'result_count', 'top_ids', 'latency_ms',
        'customer_id', 'cache_hit'];

    /**
     * The logs page's address for one state, only the parts that differ from the defaults (a view with its
     * own sort, a search, a page size, a page), so a bookmarked URL stays short. Escaped for an attribute.
     *
     * @param array<string, mixed> $s view, q, per, sort, dir, default_per
     * @param array<string, mixed> $change keys of $s to replace (a null value drops the key)
     */
    public static function logsUrl(array $s, array $change = []): string
    {
        $s = array_replace($s, $change);
        $query = ['p' => 'logs'];
        if (($s['view'] ?? 'recent') !== 'recent') {
            $query['view'] = $s['view'];
        }
        if (($s['q'] ?? '') !== '') {
            $query['q'] = $s['q'];
        }
        if (($s['per'] ?? null) !== null && $s['per'] !== ($s['default_per'] ?? null)) {
            $query['per'] = $s['per'];
        }
        if (($s['sort'] ?? '') !== '') {
            $query['sort'] = $s['sort'];
            $query['dir'] = $s['dir'];
        }
        if (($s['page'] ?? 1) > 1) {
            $query['page'] = $s['page'];
        }

        return '?' . self::e(http_build_query($query));
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, view: string,
     *        sort: string, dir: string, q: string, per: int, from: int, to: int, scan_limit: int|null,
     *        default_per: int, sizes: list<int>} $data
     */
    public static function logs(array $data): string
    {
        $t = AdminStrings::fa()['logs'];
        $ui = AdminStrings::fa()['ui'];
        $state = ['view' => $data['view'], 'q' => $data['q'], 'per' => $data['per'],
            'default_per' => $data['default_per'], 'sort' => $data['sort'], 'dir' => $data['dir']];

        $tabs = '';
        foreach ($t['views'] as $view => $label) {
            $current = $view === $data['view'] ? ' aria-current="page"' : '';
            $tabs .= '<a href="' . self::logsUrl($state, ['view' => $view, 'sort' => '', 'dir' => '']) . '"'
                . $current . '>' . self::e($label) . '</a>';
        }

        $head = '';
        foreach ($t['columns'] as $column => $label) {
            $class = in_array($column, self::LOG_CENTERED, true) ? ' class="c"' : '';
            if (!in_array($column, LogsPage::SORTS, true)) {
                $head .= '<th' . $class . '>' . self::e($label) . '</th>';
                continue;
            }
            $active = $data['sort'] === $column;
            $next = $active ? ($data['dir'] === 'asc' ? 'desc' : 'asc')
                : (in_array($column, LogsPage::TEXT, true) ? 'asc' : 'desc');
            $aria = $active ? ($data['dir'] === 'asc' ? 'ascending' : 'descending') : 'none';
            $hint = $active ? ' title="' . self::e($ui[$data['dir'] === 'asc' ? 'sort_asc' : 'sort_desc']) . '"' : '';
            $head .= '<th' . $class . ' aria-sort="' . $aria . '"><a class="sort" href="'
                . self::logsUrl($state, ['sort' => $column, 'dir' => $next, 'page' => 1]) . '"' . $hint . '>'
                . self::e($label) . '</a></th>';
        }

        $body = '';
        foreach ($data['rows'] as $row) {
            $body .= '<tr>';
            foreach (array_keys($t['columns']) as $column) {
                $value = $row[$column] ?? '';
                $class = in_array($column, self::LOG_CENTERED, true) ? ' class="c"' : '';
                if ($column === 'had_vector' || $column === 'cache_hit') {
                    $body .= '<td class="c">' . self::e($ui[($row[$column] ?? 0) ? 'yes' : 'no']) . '</td>';
                } elseif ($column === 'ts') {
                    $body .= '<td class="c nw">' . self::ts($value) . '</td>';
                } elseif (in_array($column, self::LOG_CUT, true)) {
                    // A long value is cut with an ellipsis; the whole of it is the tooltip.
                    $body .= '<td' . $class . ' dir="auto"><span class="trunc" title="' . self::e($value) . '">'
                        . self::e($value) . '</span></td>';
                } else {
                    $body .= '<td' . $class . '>' . self::e($value) . '</td>';
                }
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . count($t['columns']) . '">'
                . self::e($data['q'] === '' ? $t['empty'] : $t['no_match']) . '</td></tr>';
        }

        $hidden = '';
        foreach (
            ['view' => $data['view'] === 'recent' ? '' : $data['view'], 'sort' => $data['sort'],
            'dir' => $data['sort'] === '' ? '' : $data['dir'],
            'per' => $data['per'] === $data['default_per'] ? '' : (string) $data['per']] as $name => $value
        ) {
            $hidden .= $value === ''
                ? ''
                : '<input type="hidden" name="' . $name . '" value="' . self::e($value) . '">';
        }
        $search = '<form method="get" class="controls"><input type="hidden" name="p" value="logs">' . $hidden
            . '<input type="search" name="q" value="' . self::e($data['q']) . '" maxlength="' . LogsPage::MAX_TERM
            . '" placeholder="' . self::e($t['search']) . '" aria-label="' . self::e($t['search'])
            . '" dir="auto"> <button class="btn">' . self::e($t['search_go']) . '</button>'
            . ($data['q'] === '' ? '' : ' <a class="btn" href="' . self::logsUrl($state, ['q' => '']) . '">'
                . self::e($t['search_clear']) . '</a>') . '</form>';

        $notice = $data['scan_limit'] === null ? ''
            : '<p class="msg warn">' . self::e(AdminStrings::fill($t['scan_notice'], self::num($data['scan_limit'])))
                . '</p>';
        $pager = self::pager(
            $data,
            static fn (int $page): string => self::logsUrl($state, ['page' => $page]),
            self::perPage(['p' => 'logs'] + array_filter([
                'view' => $data['view'] === 'recent' ? '' : $data['view'], 'q' => $data['q'],
                'sort' => $data['sort'], 'dir' => $data['sort'] === '' ? '' : $data['dir'],
            ], static fn (string $v): bool => $v !== ''), $data['per'], $data['sizes'])
        );

        return self::helpBox($t['help']) . '<nav class="tabs">' . $tabs . '</nav>' . $search . $notice . $pager
            . '<div class="scroll card"><table class="logs"><thead><tr>' . $head . '</tr></thead><tbody>' . $body
            . '</tbody></table></div>' . $pager;
    }

    public static function help(): string
    {
        $g = AdminStrings::fa()['guide'];
        $html = '<p>' . self::e($g['intro']) . '</p>';
        foreach ($g['sections'] as $section) {
            $html .= '<section class="card"><h2>' . self::e($section['title']) . '</h2>';
            foreach ($section['body'] as $paragraph) {
                $html .= '<p>' . self::e($paragraph) . '</p>';
            }
            $html .= '</section><br>';
        }

        return $html;
    }

    /**
     * The range shown, the page links (first, last and a window around the current page) and, when given,
     * the rows-per-page form. Links go through $link so each page keeps its own query string.
     *
     * @param array{page: int, pages: int, from: int, to: int, total: int} $data
     * @param callable(int): string $link page number -> escaped href
     * @param string $extra markup at the end of the bar (the page-size form)
     */
    public static function pager(array $data, callable $link, string $extra = ''): string
    {
        $ui = AdminStrings::fa()['ui'];
        $range = $data['total'] === 0 ? $ui['range_none'] : AdminStrings::fill(
            $ui['range'],
            self::num($data['from']),
            self::num($data['to']),
            self::num($data['total'])
        );
        $links = '';
        if ($data['pages'] > 1) {
            if ($data['page'] > 1) {
                $links .= '<a href="' . $link($data['page'] - 1) . '" rel="prev">' . self::e($ui['prev']) . '</a>';
            }
            foreach (Paging::window($data['page'], $data['pages']) as $number) {
                if ($number === null) {
                    $links .= '<span class="gap">&hellip;</span>';
                } elseif ($number === $data['page']) {
                    $links .= '<span class="cur" aria-current="page">' . self::e(self::num($number)) . '</span>';
                } else {
                    $links .= '<a href="' . $link($number) . '" aria-label="'
                        . self::e(AdminStrings::fill($ui['page_label'], self::num($number))) . '">'
                        . self::e(self::num($number)) . '</a>';
                }
            }
            if ($data['page'] < $data['pages']) {
                $links .= '<a href="' . $link($data['page'] + 1) . '" rel="next">' . self::e($ui['next']) . '</a>';
            }
        }

        return '<div class="pager"><span class="range">' . self::e($range) . '</span>'
            . ($links === ''
                ? ''
                : '<nav class="pages" aria-label="' . self::e($ui['pages_nav']) . '">' . $links . '</nav>')
            . $extra . '</div>';
    }

    /**
     * The rows-per-page choice: a GET form back to the same page that keeps $keep and starts from page 1.
     *
     * @param array<string, string> $keep the query parameters to carry (p and the page's own state)
     * @param list<int> $sizes
     */
    public static function perPage(array $keep, int $current, array $sizes): string
    {
        $ui = AdminStrings::fa()['ui'];
        $html = '<form method="get" class="perpage">';
        foreach ($keep as $name => $value) {
            $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
        }
        $options = '';
        foreach ($sizes as $size) {
            $options .= '<option value="' . $size . '"' . ($size === $current ? ' selected' : '') . '>' . $size
                . '</option>';
        }

        return $html . '<label>' . self::e($ui['per_page']) . ' <select name="per" data-autosubmit>' . $options
            . '</select></label><noscript> <button class="btn">' . self::e($ui['apply'])
            . '</button></noscript></form>';
    }
}
