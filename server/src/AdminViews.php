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

    /** Persian digits for counts shown in prose. */
    public static function num(int|string|null $n): string
    {
        $digits = [];
        foreach (str_split('0123456789') as $i => $latin) {
            $digits[$latin] = mb_chr(0x06F0 + $i);
        }

        return strtr((string) $n, $digits);
    }

    /** A stored timestamp, kept Gregorian and left-to-right inside the RTL text. */
    private static function ts(mixed $value): string
    {
        return $value === null || $value === '' ? self::e(AdminStrings::fa()['ui']['unknown'])
            : '<bdi class="ltr">' . self::e($value) . '</bdi>';
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
                . $row($t['bundle_count'], self::e(self::num($b['count'])))
                . $row($t['model'], $ltr($b['model'])) . '</dl>';
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

        $d = $s['doctor'];
        $cards .= '<section class="card"><h2>' . self::e($t['doctor']) . '</h2>';
        $errors = $d['errors'] === [] ? ''
            : $badge('bad', $t['doctor_errors'] . ': ' . self::num(count($d['errors']))) . ' ';
        $warnings = $d['warnings'] === [] ? ''
            : $badge('warn', $t['doctor_warnings'] . ': ' . self::num(count($d['warnings'])));
        $cards .= $errors . $warnings === '' ? '<p>' . $badge('ok', $t['doctor_ok']) . '</p>'
            : '<p>' . $errors . $warnings . '</p>';
        foreach (['errors', 'warnings'] as $kind) {
            if ($d[$kind] !== []) {
                $cards .= '<ul class="keys">';
                foreach ($d[$kind] as $line) {
                    $cards .= '<li>' . self::e($line) . '</li>';
                }
                $cards .= '</ul>';
            }
        }
        $cards .= '<p class="note">' . self::e($t['doctor_note']) . '</p></section>';

        return self::helpBox($t['help']) . '<div class="cards">' . $cards . '</div>';
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $data
     */
    public static function audit(array $data): string
    {
        $t = AdminStrings::fa()['audit'];
        $rows = '';
        foreach ($data['rows'] as $row) {
            $label = $t['actions'][$row['action']] ?? $row['action'];
            $rows .= '<tr><td>' . self::ts($row['ts']) . '</td><td><bdi class="ltr">' . self::e($row['ip'])
                . '</bdi></td><td>' . self::e($label) . '</td><td>' . self::e($row['detail']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">' . self::e($t['empty']) . '</td></tr>';
        }
        $pager = self::pager($t, $data, static fn (int $page): string => '?p=audit&amp;page=' . $page);

        $head = '';
        foreach (['time', 'ip', 'action', 'detail'] as $column) {
            $head .= '<th>' . self::e($t[$column]) . '</th>';
        }

        return self::helpBox($t['help']) . $pager . '<div class="scroll card"><table><thead><tr>' . $head
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager;
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, view: string} $data
     */
    public static function logs(array $data): string
    {
        $t = AdminStrings::fa()['logs'];
        $tabs = '';
        foreach ($t['views'] as $view => $label) {
            $current = $view === $data['view'] ? ' aria-current="page"' : '';
            $tabs .= '<a href="?p=logs&amp;view=' . self::e($view) . '"' . $current . '>' . self::e($label) . '</a>';
        }
        $head = '';
        foreach ($t['columns'] as $label) {
            $head .= '<th>' . self::e($label) . '</th>';
        }
        $body = '';
        foreach ($data['rows'] as $row) {
            $body .= '<tr>';
            foreach (array_keys($t['columns']) as $column) {
                $value = $row[$column] ?? '';
                if ($column === 'had_vector' || $column === 'cache_hit') {
                    $value = ($row[$column] ?? 0) ? AdminStrings::fa()['ui']['yes'] : AdminStrings::fa()['ui']['no'];
                }
                $body .= '<td dir="auto">' . self::e($value) . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . count($t['columns']) . '">' . self::e($t['empty']) . '</td></tr>';
        }
        $view = $data['view'];
        $pager = self::pager(
            $t,
            $data,
            static fn (int $page): string => '?p=logs&amp;view=' . $view . '&amp;page=' . $page
        );

        return self::helpBox($t['help']) . '<nav class="tabs">' . $tabs . '</nav>' . $pager
            . '<div class="scroll card"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body
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
     * @param array<string, mixed> $t strings with `page`, `newer`, `older`
     * @param array{page: int, pages: int, total: int} $data
     * @param callable(int): string $link
     */
    private static function pager(array $t, array $data, callable $link): string
    {
        $html = '<div class="pager"><span>' . self::e(AdminStrings::fill(
            $t['page'],
            self::num($data['page']),
            self::num($data['pages']),
            self::num($data['total'])
        )) . '</span>';
        if ($data['page'] > 1) {
            $html .= '<a href="' . $link($data['page'] - 1) . '">' . self::e($t['newer']) . '</a>';
        }
        if ($data['page'] < $data['pages']) {
            $html .= '<a href="' . $link($data['page'] + 1) . '">' . self::e($t['older']) . '</a>';
        }

        return $html . '</div>';
    }
}
