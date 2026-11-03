<?php

declare(strict_types=1);

namespace App;

/**
 * HTML of the admin panel's VPS page (`?p=vps`): summary, hourly strips, incidents and the call list.
 * Every dynamic value is escaped; the Persian text comes from {@see AdminStrings} (`vps`). The strips are
 * inline SVG with no script, so the page needs nothing beyond the panel's own CSP.
 */
final class AdminVpsViews
{
    private const WINDOWS = [24, 168];
    private const SUMMARY_KINDS = ['search', 'probe'];
    private const HOURS = 168;
    private const STRIP_HEIGHT = 24;

    /** Extra styles of the page (colours come from the panel's theme variables). */
    public static function css(): string
    {
        return '.scroll .badge{white-space:nowrap}'
            . '.strip{display:block;width:100%;height:28px;background:var(--help);border-radius:4px}'
            . '.strip .o-ok{fill:var(--ok)}.strip .o-timeout{fill:var(--bad)}.strip .o-unreachable{fill:var(--s2)}'
            . '.strip .o-http_error{fill:var(--warn)}.strip .o-bad_body{fill:var(--s1)}'
            . '.strip .o-not_configured{fill:var(--ink2)}'
            . '.strips h3{margin:12px 0 2px}.strips .lbl{margin:6px 0 0;font-size:12px;color:var(--ink2)}'
            . '.axis{display:flex;justify-content:space-between;font-size:12px;color:var(--ink2)}'
            . '.vps-filters label{display:inline-flex;gap:4px;align-items:center}'
            . '.vps-filters select,.vps-filters input[type=date]{font:inherit;padding:4px 8px;'
            . 'border:1px solid var(--line);'
            . 'border-radius:6px;background:var(--surface);color:var(--ink)}';
    }

    private static function e(mixed $value): string
    {
        return AdminPage::esc((string) $value);
    }

    private static function ms(mixed $value): string
    {
        return $value === null || $value === '' ? '-' : AdminViews::num((int) round((float) $value));
    }

    /** 12.50 -> "12.5", 3.00 -> "3". */
    private static function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private static function badge(string $outcome): string
    {
        $labels = AdminStrings::fa()['vps']['outcomes'];

        return '<span class="badge ' . ($outcome === 'ok' ? 'ok' : ($outcome === 'not_configured' ? 'off' : 'bad'))
            . '">' . self::e($labels[$outcome] ?? $outcome) . '</span>';
    }

    /**
     * @param array<string, mixed> $d available, csrf, probed (an outcome or ''), summary (hours => kind =>
     *        VpsCallReport::summary()), hourly (kind => VpsCallReport::hourly()), incidents, list
     *        (VpsCallReport::rows()), filters, default_per, sizes
     */
    public static function page(array $d): string
    {
        $t = AdminStrings::fa()['vps'];
        $help = AdminViews::helpBox($t['help']) . self::outcomeHelp();
        if (!$d['available']) {
            return $help . '<p class="msg warn" role="status">' . self::e($t['missing']) . '</p>';
        }

        return $help . self::probeCard($d) . self::summary($d['summary']) . self::hourly($d['hourly'])
            . self::incidents($d['incidents']) . self::calls($d);
    }

    private static function outcomeHelp(): string
    {
        $t = AdminStrings::fa()['vps'];
        $html = '<details class="helpbox"><summary>' . self::e($t['outcomes']['timeout'] . ' / '
            . $t['outcomes']['unreachable'] . ' / ' . $t['outcomes']['http_error'] . ' / '
            . $t['outcomes']['bad_body']) . '</summary><dl>';
        foreach ($t['outcome_help'] as $outcome => $text) {
            $html .= '<dt>' . self::badge($outcome) . '</dt><dd>' . self::e($text) . '</dd>';
        }

        return $html . '</dl></details>';
    }

    /** @param array<string, mixed> $d */
    private static function probeCard(array $d): string
    {
        $t = AdminStrings::fa()['vps']['probe'];
        $banner = '';
        if ($d['probed'] !== '') {
            $banner = '<p class="msg ' . ($d['probed'] === 'ok' ? 'ok' : 'bad') . '" role="status">'
                . self::badge($d['probed']) . ' ' . self::e(AdminStrings::fill(
                    $d['probed_ms'] === null ? $t['done'] : $t['done_ms'],
                    AdminStrings::fa()['vps']['outcomes'][$d['probed']] ?? $d['probed'],
                    $d['probed_ms'] === null ? '' : AdminViews::num($d['probed_ms'])
                )) . '</p>';
        }

        return $banner
            . '<section class="card"><form method="post" action="?p=vps"><input type="hidden" name="csrf" value="'
            . self::e($d['csrf']) . '"><button class="btn" name="action" value="vps_probe">' . self::e($t['button'])
            . '</button> <span class="note">' . self::e($t['note']) . '</span></form></section>';
    }

    /** @param array<int, array<string, array<string, mixed>>> $summary hours => kind => summary */
    private static function summary(array $summary): string
    {
        $t = AdminStrings::fa()['vps']['summary'];
        $kinds = AdminStrings::fa()['vps']['kinds'];
        $outcomes = AdminStrings::fa()['vps']['outcomes'];
        $head = '<th>' . self::e($t['window']) . '</th><th>' . self::e($t['kind']) . '</th><th class="c">'
            . self::e($t['calls']) . '</th><th class="c">' . self::e($t['ok_pct']) . '</th>';
        foreach (VpsCallLog::OUTCOMES as $outcome) {
            if ($outcome !== 'ok' && $outcome !== 'not_configured') {
                $head .= '<th class="c">' . self::e($outcomes[$outcome]) . '</th>';
            }
        }
        $head .= '<th class="c">' . self::e($t['p50']) . '</th><th class="c">' . self::e($t['p95'])
            . '</th><th class="c">' . self::e($t['max']) . '</th><th class="c" title="' . self::e($t['near_hint'])
            . '">'
            . self::e($t['near']) . '</th>';

        $body = '';
        foreach (self::WINDOWS as $hours) {
            foreach (self::SUMMARY_KINDS as $kind) {
                $s = $summary[$hours][$kind];
                $body .= '<tr><td>' . self::e($t['windows'][(string) $hours]) . '</td><td>' . self::e($kinds[$kind])
                    . '</td>';
                if ($s['calls'] === 0 && $s['skipped'] === 0) {
                    $body .= '<td colspan="11">' . self::e($t['empty']) . '</td></tr>';
                    continue;
                }
                $body .= '<td class="c">' . AdminViews::num($s['calls'])
                    . '</td><td class="c">' . self::e($s['ok_pct'] ?? '-')
                    . '</td>';
                foreach (VpsCallLog::OUTCOMES as $outcome) {
                    if ($outcome !== 'ok' && $outcome !== 'not_configured') {
                        $body .= '<td class="c">' . AdminViews::num($s['outcomes'][$outcome]) . '</td>';
                    }
                }
                $body .= '<td class="c">' . self::ms($s['p50']) . '</td><td class="c">' . self::ms($s['p95'])
                    . '</td><td class="c">' . self::ms($s['max']) . '</td><td class="c">' . AdminViews::num($s['near'])
                    . ' (' . self::e($s['near_pct']) . '%)</td></tr>';
            }
        }

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2><div class="scroll"><table><thead><tr>'
            . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
    }

    /** @param array<string, list<array{hour: string, counts: array<string, int>}>> $hourly kind => series */
    private static function hourly(array $hourly): string
    {
        $t = AdminStrings::fa()['vps']['hourly'];
        $kinds = AdminStrings::fa()['vps']['kinds'];
        $groups = '';
        $any = false;
        foreach (self::SUMMARY_KINDS as $kind) {
            $rows = '';
            foreach (VpsCallLog::OUTCOMES as $outcome) {
                $counts = array_map(static fn (array $h): int => (int) ($h['counts'][$outcome] ?? 0), $hourly[$kind]);
                $total = array_sum($counts);
                if ($total === 0) {
                    continue;
                }
                $any = true;
                $rows .= self::strip($outcome, $hourly[$kind], $counts, $total, $t);
            }
            if ($rows !== '') {
                $groups .= '<h3>' . self::e($kinds[$kind]) . '</h3>' . $rows;
            }
        }
        $first = $hourly['search'][0]['hour'] ?? '';
        $last = $hourly['search'][self::HOURS - 1]['hour'] ?? '';

        return '<section class="card strips"><h2>' . self::e($t['title']) . '</h2><p class="note">'
            . self::e($t['per_hour']) . '</p>' . ($any ? $groups
                . '<div class="axis"><bdi class="ltr">' . self::e($first) . '</bdi><bdi class="ltr">' . self::e($last)
                . '</bdi></div>' : '<p>' . self::e($t['none']) . '</p>') . '</section>';
    }

    /**
     * @param list<array{hour: string, counts: array<string, int>}> $series
     * @param list<int> $counts
     * @param array<string, string> $t
     */
    private static function strip(string $outcome, array $series, array $counts, int $total, array $t): string
    {
        $labels = AdminStrings::fa()['vps']['outcomes'];
        $max = max($counts);
        $bars = '';
        foreach ($counts as $i => $count) {
            if ($count === 0) {
                continue;
            }
            $height = max(1, (int) round($count / $max * self::STRIP_HEIGHT));
            $bars .= '<rect class="o-' . self::e($outcome) . '" x="' . $i . '" y="' . (self::STRIP_HEIGHT - $height)
                . '" width="0.85" height="' . $height . '"><title>' . self::e(
                    $series[$i]['hour'] . ' ' . AdminStrings::fill($t['tip'], $labels[$outcome], (string) $count)
                ) . '</title></rect>';
        }

        return '<p class="lbl">' . self::e(AdminStrings::fill(
            $t['row'],
            $labels[$outcome],
            AdminViews::num($total),
            AdminViews::num($max)
        )) . '</p><svg class="strip" viewBox="0 0 ' . self::HOURS . ' ' . self::STRIP_HEIGHT
            . '" preserveAspectRatio="none" role="img" aria-label="' . self::e($labels[$outcome]) . '" dir="ltr">'
            . $bars . '</svg>';
    }

    /** @param list<array<string, mixed>> $incidents */
    private static function incidents(array $incidents): string
    {
        $t = AdminStrings::fa()['vps']['incidents'];
        $kinds = AdminStrings::fa()['vps']['kinds'];
        $rows = '';
        foreach ($incidents as $i) {
            $seconds = max(0, (int) strtotime($i['end'] . ' UTC') - (int) strtotime($i['start'] . ' UTC'));
            $summary = [];
            foreach ($i['outcomes'] as $outcome => $count) {
                $summary[] = self::badge($outcome) . ' ' . AdminViews::num($count);
            }
            $rows .= '<tr><td class="c nw">' . self::time($i['start']) . '</td><td class="c nw">'
                . self::time($i['end'])
                . '</td><td class="c">' . AdminViews::num($seconds) . '</td><td class="c">'
                . AdminViews::num($i['count'])
                . '</td><td>' . implode(' ', $summary) . '</td><td>'
                . self::e(implode(AdminStrings::fa()['ui']['list_sep'], array_map(
                    static fn (string $k): string => $kinds[$k] ?? $k,
                    $i['kinds']
                ))) . '</td><td class="c">' . self::ms($i['before_ms']) . '</td><td class="c">'
                    . self::ms($i['after_ms'])
                . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8">' . self::e($t['empty']) . '</td></tr>';
        }

        $head = '';
        foreach (
            ['start' => 'c', 'end' => 'c', 'seconds' => 'c', 'count' => 'c', 'outcomes' => '', 'kinds' => '',
            'before' => 'c', 'after' => 'c'] as $column => $class
        ) {
            $head .= '<th' . ($class === '' ? '' : ' class="c"') . '>' . self::e($t[$column]) . '</th>';
        }

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2><p class="note">' . self::e($t['note'])
            . '</p><div class="scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $rows
            . '</tbody></table></div></section>';
    }

    /** A UTC timestamp as stored, left to right (the page says UTC; the Jalali date would suggest local time). */
    private static function time(mixed $value): string
    {
        return '<bdi class="ltr">' . self::e(AdminDate::short((string) $value)) . '</bdi>';
    }

    /**
     * The page's address for one list state.
     *
     * @param array<string, mixed> $s filters + per + default_per
     * @param array<string, mixed> $change
     */
    public static function url(array $s, array $change = [], string $extra = ''): string
    {
        $s = array_replace($s, $change);
        $query = ['p' => 'vps'] + ($extra === '' ? [] : ['export' => $extra]);
        if (($s['view'] ?? 'failures') !== 'failures') {
            $query['view'] = $s['view'];
        }
        foreach (['outcome', 'kind', 'from', 'to'] as $key) {
            if (($s[$key] ?? '') !== '') {
                $query[$key] = $s[$key];
            }
        }
        if (($s['per'] ?? null) !== null && $s['per'] !== ($s['default_per'] ?? null)) {
            $query['per'] = $s['per'];
        }
        if (($s['page'] ?? 1) > 1) {
            $query['page'] = $s['page'];
        }

        return '?' . self::e(http_build_query($query));
    }

    /** @param array<string, mixed> $d */
    private static function calls(array $d): string
    {
        $t = AdminStrings::fa()['vps']['list'];
        $kinds = AdminStrings::fa()['vps']['kinds'];
        $labels = AdminStrings::fa()['vps']['outcomes'];
        $f = $d['filters'];
        $list = $d['list'];
        $state = $f + ['per' => $list['per'], 'default_per' => $d['default_per']];

        $tabs = '';
        foreach ($t['views'] as $view => $label) {
            $tabs .= '<a href="' . self::url($state, ['view' => $view, 'page' => 1]) . '"'
                . ($view === $f['view'] ? ' aria-current="page"' : '') . '>' . self::e($label) . '</a>';
        }

        $select = static function (string $name, string $label, array $options, string $current): string {
            $html = '<label>' . self::e($label) . ' <select name="' . $name . '">';
            foreach ($options as $value => $text) {
                $html .= '<option value="' . self::e($value) . '"' . ((string) $value === $current ? ' selected' : '')
                    . '>' . self::e($text) . '</option>';
            }

            return $html . '</select></label>';
        };
        $form = '<form method="get" class="controls vps-filters"><input type="hidden" name="p" value="vps">'
            . ($f['view'] === 'failures' ? '' : '<input type="hidden" name="view" value="' . self::e($f['view']) . '">')
            . ($list['per'] === $d['default_per'] ? '' : '<input type="hidden" name="per" value="'
            . self::e($list['per']) . '">')
            . $select(
                'outcome',
                $t['outcome'],
                ['' => $t['any'], VpsCallReport::FAILED => $t['failed']] + $labels,
                $f['outcome']
            )
            . $select('kind', $t['kind'], ['' => $t['any']] + $kinds, $f['kind'])
            . '<label>' . self::e($t['from']) . ' <input type="date" name="from" value="' . self::e($f['from'])
            . '"></label>'
            . '<label>' . self::e($t['to']) . ' <input type="date" name="to" value="' . self::e($f['to']) . '"></label>'
            . '<button class="btn">' . self::e($t['apply']) . '</button> <a class="btn" href="'
            . self::url([], ['view' => $f['view']]) . '">' . self::e($t['clear']) . '</a> <a class="btn" href="'
            . self::url($state, [], 'csv') . '">' . self::e($t['export']) . '</a></form>';

        $columns = $t['columns'];
        $head = '';
        foreach ($columns as $label) {
            $head .= '<th class="c">' . self::e($label) . '</th>';
        }
        $body = '';
        foreach ($list['rows'] as $row) {
            $body .= '<tr>';
            foreach (array_keys($columns) as $column) {
                $value = $row[$column] ?? null;
                $body .= match ($column) {
                    'ts' => '<td class="c nw">' . self::time($value) . '</td>',
                    'kind' => '<td class="c">' . self::e($kinds[$value] ?? $value) . '</td>',
                    'outcome' => '<td class="c">' . self::badge((string) $value) . '</td>',
                    'error', 'normalized_q' => '<td dir="auto"><span class="trunc" title="' . self::e($value) . '">'
                        . self::e($value) . '</span></td>',
                    'request_id', 'sent_hash' => '<td class="c"><bdi class="ltr">' . self::e($value) . '</bdi></td>',
                    'source' => '<td class="c">' . self::e($value) . '</td>',
                    'sent_differs' => '<td class="c">'
                        . self::e(AdminStrings::fa()['ui'][$value ? 'yes' : 'no']) . '</td>',
                    'vps_queue_ms', 'vps_embed_ms', 'vps_search_ms', 'vps_took_ms' => '<td class="c">'
                        . self::e($value === null ? '-' : self::decimal($value))
                        . '</td>',
                    default => '<td class="c">'
                        . ($column === 'http_status' ? self::e($value ?? '-') : self::ms($value))
                        . '</td>',
                };
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . count($columns) . '">' . self::e($t['empty']) . '</td></tr>';
        }

        $keep = ['p' => 'vps'] + array_filter(
            ['view' => $f['view'] === 'failures' ? '' : $f['view']] + array_diff_key($f, ['view' => 1]),
            static fn (string $v): bool => $v !== ''
        );
        $pager = AdminViews::pager(
            $list,
            static fn (int $page): string => self::url($state, ['page' => $page]),
            AdminViews::perPage($keep, $list['per'], $d['sizes'])
        );

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2><nav class="tabs">' . $tabs . '</nav>'
            . $form
            . $pager . '<div class="scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body
            . '</tbody></table></div>' . $pager . '</section>';
    }
}
