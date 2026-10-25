<?php

declare(strict_types=1);

namespace App;

/**
 * HTML of the admin panel's operations page (M40). Escapes every dynamic value; the Persian text comes
 * from {@see AdminStrings} (`ops`).
 */
final class AdminOpsViews
{
    private static function e(mixed $value): string
    {
        return AdminPage::esc((string) $value);
    }

    /**
     * @param array<string, mixed> $d `reload` (Reload::describe()), `vps` and `redis` (AdminStatus probes),
     *        `doctor`, `recent` (audit rows of AdminOps::ACTIONS, newest first), `done` (audit id whose
     *        result is the banner, 0 for none), `csrf`, `prefix` (the result cache's key prefix)
     */
    public static function page(array $d): string
    {
        $t = AdminStrings::fa()['ops'];
        $banner = '';
        foreach ($d['recent'] as $row) {
            if ((int) $d['done'] > 0 && (int) $row['id'] === (int) $d['done']) {
                [$kind, $message] = self::message($row);
                $banner = '<p class="msg ' . $kind . '" role="status">' . self::e($message) . '</p>';
            }
        }
        $running = '<p class="msg" id="running" role="status" hidden data-text="' . self::e($t['running']) . '"></p>';
        $form = static function (string $action, string $label, string $confirm, bool $enabled) use ($d): string {
            return '<form method="post" action="?p=ops" data-busy><input type="hidden" name="csrf" value="'
                . self::e($d['csrf'])
                . '"><button class="btn danger" name="action" value="' . $action . '"' . ($enabled ? '' : ' disabled')
                . ' data-confirm="' . self::e($confirm) . '">' . self::e($label) . '</button></form>';
        };

        return AdminViews::helpBox($t['help']) . $banner . $running . '<div class="cards ops">'
            . self::reloadCard($d['reload'], (string) $d['csrf'])
            . self::rollbackCard($d['reload']['rollback'], $form)
            . self::cacheCard($d['redis'], (string) $d['prefix'], $form)
            . self::vpsCard($d['vps'], $form)
            . self::doctorCard($d['doctor'])
            . '</div>' . self::recent($d['recent']);
    }

    /** @param array<string, mixed> $r Reload::describe() */
    private static function reloadCard(array $r, string $csrf): string
    {
        $t = AdminStrings::fa()['ops']['reload'];
        $in = $r['incoming'];
        $live = $r['live'];
        $fields = [
            'built_at' => $t['built_at'], 'normalization_version' => $t['norm_version'], 'count' => $t['count'],
            'model' => $t['model'], 'dim' => $t['dim'],
        ];
        $cell = static function (array $bundle, string $field): string {
            if (!$bundle['present'] || $bundle[$field] === null) {
                return '-';
            }

            return in_array($field, ['count', 'dim', 'normalization_version'], true)
                ? self::e(AdminViews::num($bundle[$field]))
                : '<bdi class="ltr">' . self::e($bundle[$field]) . '</bdi>';
        };
        $table = '<table class="cmp"><thead><tr><th></th><th>' . self::e($t['incoming']) . '</th><th>'
            . self::e($t['live'])
            . '</th></tr></thead><tbody>';
        foreach ($fields as $field => $label) {
            $differs = $in['present'] && $live['present'] && $in[$field] !== $live[$field];
            $table .= '<tr' . ($differs ? ' class="diff"' : '') . '><th scope="row">' . self::e($label) . '</th><td>'
                . $cell($in, $field) . '</td><td>' . $cell($live, $field) . '</td></tr>';
        }
        $table .= '</tbody></table>';
        if (!$in['present']) {
            return '<section class="card wide"><h2>' . self::e($t['title']) . '</h2>' . $table
                . '<p><span class="badge warn">' . self::e($t['none_incoming']) . '</span></p></section>';
        }

        $diff = '';
        if ($r['diff'] !== null) {
            $d = $r['diff'];
            $lines = [];
            if ($d['count_delta'] !== null) {
                $sign = $d['count_delta'] > 0 ? '+' : '';
                $lines[] = AdminStrings::fill($t['diff_count'], $sign . AdminViews::num($d['count_delta']));
            }
            if ($d['added'] !== null) {
                $lines[] = AdminStrings::fill(
                    $t['diff_ids'],
                    AdminViews::num($d['added']),
                    AdminViews::num($d['removed'])
                );
            }
            $lines[] = $d['changed'] === []
                ? $t['diff_same']
                : AdminStrings::fill($t['diff_changed'], implode(', ', $d['changed']));
            $diff = '<p class="note"><strong>' . self::e($t['diff']) . '</strong></p><ul class="difflist"><li>'
                . implode('</li><li>', array_map([self::class, 'e'], $lines)) . '</li></ul>';
        }
        $stagingText = !$r['staging']['exists'] ? $t['staging_none']
            : ($r['staging']['rows'] === null
                ? '?'
                : AdminStrings::fill($t['staging_rows'], AdminViews::num($r['staging']['rows'])));
        $facts = '<dl class="kv"><dt>' . self::e($t['staging']) . '</dt><dd>' . self::e($stagingText) . '</dd><dt>'
            . self::e($t['load_sql']) . '</dt><dd>' . self::e($r['load_sql'] ? $t['load_sql_yes'] : $t['load_sql_no'])
            . '</dd></dl>';
        $option = '<label class="opt"><input type="checkbox" name="load_staging" value="1"'
            . ($r['load_sql'] ? ' checked' : ' disabled')
            . '> ' . self::e($t['load_option']) . '</label>';
        $actions = '<form method="post" action="?p=ops" data-busy class="actions">'
            . '<input type="hidden" name="csrf" value="' . self::e($csrf) . '">' . $option
            . '<div><button class="btn" name="action" value="validate">' . self::e($t['validate'])
            . '</button> <button class="btn primary" name="action" value="apply" data-confirm="'
            . self::e($t['apply_confirm']) . '">'
            . self::e($t['apply']) . '</button></div></form>';

        return '<section class="card wide"><h2>' . self::e($t['title']) . '</h2>' . $table . $diff . $facts . $actions
            . '</section>';
    }

    /**
     * @param array<string, mixed> $r the `rollback` part of Reload::describe()
     * @param callable(string, string, string, bool): string $form
     */
    private static function rollbackCard(array $r, callable $form): string
    {
        $t = AdminStrings::fa()['ops']['rollback'];
        $p = $r['previous'];
        $summary = '';
        if ($p['present']) {
            $summary = '<dl class="kv"><dt>' . self::e(AdminStrings::fa()['ops']['reload']['built_at']) . '</dt><dd>'
                . AdminViews::ts($p['built_at']) . '</dd><dt>' . self::e(AdminStrings::fa()['ops']['reload']['count'])
                . '</dt><dd>' . self::e(AdminViews::num($p['count'])) . '</dd></dl>';
        }

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2><p class="note">' . self::e($t['previous'])
            . '</p>'
            . $summary . ($r['possible'] ? '<p class="note">' . self::e($t['note']) . '</p>'
                : '<p class="note">' . self::e($t['disabled']) . '</p>')
            . $form('rollback', $t['button'], $t['confirm'], (bool) $r['possible']) . '</section>';
    }

    /**
     * @param array<string, mixed> $redis AdminStatus::redis()
     * @param callable(string, string, string, bool): string $form
     */
    private static function cacheCard(array $redis, string $prefix, callable $form): string
    {
        $t = AdminStrings::fa()['ops']['cache'];
        if ($redis['state'] === 'off') {
            $body = '<p><span class="badge off">' . self::e($t['off']) . '</span></p>';
        } elseif ($redis['state'] === 'unreachable') {
            $body = '<p><span class="badge bad">' . self::e($t['unreachable']) . '</span></p>';
        } else {
            $body = '<dl class="kv"><dt>' . self::e($t['state']) . '</dt><dd><span class="badge ok">'
                . self::e($t['ok'])
                . '</span></dd><dt>' . self::e($t['keys']) . '</dt><dd>' . self::e(AdminViews::num($redis['keys']))
                . '</dd><dt>' . self::e($t['prefix']) . '</dt><dd><bdi class="ltr">' . self::e($prefix)
                . '</bdi></dd></dl>';
        }

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2>' . $body
            . $form('flush_cache', $t['button'], $t['confirm'], $redis['state'] === 'ok') . '</section>';
    }

    /**
     * @param array<string, mixed> $vps AdminStatus::vps()
     * @param callable(string, string, string, bool): string $form
     */
    private static function vpsCard(array $vps, callable $form): string
    {
        $t = AdminStrings::fa()['ops']['vps'];
        if ($vps['state'] === 'off') {
            $body = '<p><span class="badge off">' . self::e($t['off']) . '</span></p>';
        } elseif ($vps['state'] === 'unreachable') {
            $body = '<p><span class="badge bad">' . self::e($t['unreachable']) . '</span></p>';
        } else {
            $health = $vps['state'] === 'ok'
                ? '<span class="badge ok">' . self::e($t['ok']) . '</span>' : '<span class="badge bad">'
                    . self::e($t['error']) . '</span>';
            $body = '<dl class="kv"><dt>' . self::e($t['title']) . '</dt><dd>' . $health . '</dd><dt>'
                . self::e($t['count'])
                . '</dt><dd>' . self::e(AdminViews::num($vps['count'] ?? '-')) . '</dd><dt>' . self::e($t['model'])
                . '</dt><dd><bdi class="ltr">' . self::e($vps['model'] ?? '-') . '</bdi></dd><dt>'
                . self::e($t['built_at'])
                . '</dt><dd>' . AdminViews::ts($vps['built_at'] ?? null) . '</dd></dl>';
        }

        return '<section class="card"><h2>' . self::e($t['title']) . '</h2>' . $body . '<p class="note">'
            . self::e($t['note'])
            . '</p>' . $form('vps_reload', $t['button'], $t['confirm'], $vps['state'] !== 'off') . '</section>';
    }

    /** @param array{errors: list<string>, warnings: list<string>, info: list<string>} $doctor */
    private static function doctorCard(array $doctor): string
    {
        $t = AdminStrings::fa()['ops']['doctor'];

        return '<section class="card" id="doctor"><h2>' . self::e($t['title']) . '</h2>'
            . AdminViews::doctorBody($doctor)
            . '<p><a class="btn" href="?p=ops#doctor">' . self::e($t['again']) . '</a></p></section>';
    }

    /** @param list<array<string, mixed>> $rows */
    private static function recent(array $rows): string
    {
        $t = AdminStrings::fa()['ops']['recent'];
        $labels = AdminStrings::fa()['audit']['actions'];
        $body = '';
        foreach ($rows as $row) {
            [$kind, $message] = self::message($row);
            $body .= '<tr><td>' . AdminViews::ts($row['ts']) . '</td><td>'
                . self::e($labels[$row['action']] ?? $row['action'])
                . '</td><td><span class="badge ' . ($kind === 'ok' ? 'ok' : 'bad') . '">'
                . self::e($kind === 'ok' ? $t['ok'] : $t['failed'])
                . '</span> ' . self::e($message) . '</td><td><bdi class="ltr">' . self::e($row['ip'])
                . '</bdi></td></tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="4">' . self::e($t['empty']) . '</td></tr>';
        }

        return '<section class="card recent"><h2>' . self::e($t['title'])
            . '</h2><div class="scroll"><table><thead><tr><th>'
            . self::e($t['time']) . '</th><th>' . self::e($t['action']) . '</th><th>' . self::e($t['result'])
            . '</th><th>'
            . self::e($t['ip']) . '</th></tr></thead><tbody>' . $body . '</tbody></table></div></section>';
    }

    /**
     * The sentence for one audit row of an operation, and whether it succeeded.
     *
     * @param array<string, mixed> $row an `admin_audit` row
     * @return array{0: string, 1: string} 'ok' | 'bad', the message
     */
    public static function message(array $row): array
    {
        $t = AdminStrings::fa()['ops'];
        $detail = json_decode((string) $row['detail'], true);
        $detail = is_array($detail) ? $detail : [];
        $action = (string) $row['action'];
        $seconds = isset($detail['s'])
            ? AdminStrings::fill($t['results']['in'], number_format((float) $detail['s'], 1, '.', ''))
            : '';
        $count = AdminViews::num($detail['count'] ?? $detail['removed'] ?? 0);
        if ($action === Audit::RELOAD_API || ($detail['ok'] ?? false) === true) {
            $text = AdminStrings::fill($t['results'][$action] ?? $t['results']['reload_apply'], $count);
            if (array_key_exists('cache_flushed', $detail)) {
                $text .= $detail['cache_flushed'] ? $t['results']['cache_also'] : $t['results']['cache_not'];
            }

            return ['ok', trim($text . ' ' . $seconds)];
        }
        $reason = (string) ($detail['reason'] ?? 'internal');
        $why = $t['reasons'][$reason]
            ?? (str_starts_with($reason, 'unreachable') ? $t['reasons']['unreachable'] : $reason);

        return ['bad', trim(AdminStrings::fill($t['results']['failed'], $why) . ' ' . $seconds)];
    }
}
