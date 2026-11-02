<?php

declare(strict_types=1);

namespace App;

/**
 * The settings page of the admin panel (M46): a pure HTML builder over the data the entry file collects.
 * Every value is escaped here, every sentence is in {@see AdminStrings} (`settings`). Credentials are not
 * listed at all (not even their key names): only keys {@see Config::editability()} calls editable or
 * readonly are rendered.
 */
final class AdminSettingsViews
{
    private static function e(mixed $value): string
    {
        return AdminPage::esc((string) $value);
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    /**
     * @param array<string, mixed> $d tab, csrf, state (see {@see AdminSettings::state()}), writable, result,
     *        saved, flush, backups, diff
     */
    public static function page(array $d): string
    {
        $t = AdminStrings::fa()['settings'];
        $tab = ($d['tab'] ?? '') === 'history' ? 'history' : 'settings';
        $tabs = '';
        foreach ($t['tabs'] as $key => $label) {
            $tabs .= '<a href="?p=settings&amp;tab=' . $key . '"' . ($key === $tab ? ' aria-current="page"' : '') . '>'
                . self::e($label) . '</a>';
        }
        $head = AdminViews::helpBox($t['help']) . '<nav class="tabs">' . $tabs . '</nav>' . self::banners($t, $d);

        if ($tab === 'history') {
            $outcome = ($d['result'] ?? null) === null ? '' : self::resultBox($t, $d['result']);

            return $head . $outcome . self::history($t, $d);
        }

        return $head . self::form($t, $d);
    }

    /** @param array<string, mixed> $d */
    private static function banners(array $t, array $d): string
    {
        $html = '';
        $stored = $d['state']['stored'];
        if (($d['saved'] ?? '') === 'saved' || ($d['saved'] ?? '') === 'restored') {
            $html .= '<div class="msg ok">' . self::e($t[$d['saved']]) . '</div>';
            if (!empty($d['flush'])) {
                $html .= '<div class="msg warn">' . self::e($t['flush_notice']) . ' <a class="btn" href="?p=ops">'
                    . self::e($t['flush_link']) . '</a></div>';
            }
        }
        if (empty($d['writable'])) {
            $html .= '<div class="msg bad">' . self::e($t['storage_unwritable']) . '</div>';
        }
        if ($stored['problem'] !== null) {
            $html .= '<div class="msg bad">'
                . self::e($t['problem'][$stored['problem']] ?? $stored['problem']) . '</div>';
        }
        $bad = array_merge($stored['ignored'], $stored['invalid']);
        if ($bad !== []) {
            $items = '';
            foreach ($bad as $key) {
                $items .= '<li><bdi class="ltr">' . self::e($key) . '</bdi></li>';
            }
            $html .= '<div class="msg warn">' . self::e($t['ignored_keys']) . '<ul>' . $items . '</ul></div>';
        }

        return $html;
    }

    /** @param array<string, mixed> $d */
    private static function form(array $t, array $d): string
    {
        $state = $d['state'];
        $result = $d['result'] ?? null;
        $form = (array) ($result['form'] ?? []);
        $messages = $result === null ? '' : self::resultBox($t, $result);

        $sections = '';
        foreach (SettingRules::sections() as $section => $paths) {
            $rows = '';
            foreach ($paths as $path) {
                $rows .= self::row($t, $d, $path, $form, (array) ($result['errors'] ?? []));
            }
            $sections .= '<details class="card sgroup" open><summary><strong>'
                . self::e($t['sections'][$section] ?? $section) . '</strong> <span class="note">('
                . AdminViews::num(count($paths)) . ')</span></summary><div class="scroll"><table class="stable"><thead>'
                . '<tr><th>' . self::e($t['columns']['setting']) . '</th><th>' . self::e($t['columns']['value'])
                . '</th><th>' . self::e($t['columns']['state']) . '</th></tr></thead><tbody>' . $rows
                . '</tbody></table></div></details>';
        }

        $warned = $result !== null && ($result['warnings'] ?? []) !== [];
        $confirm = $warned
            ? '<label class="confirm"><input type="checkbox" name="confirm" value="1"> ' . self::e($t['confirm_label'])
                . '</label>'
            : '';
        $version = (string) ($result['version'] ?? $state['stored']['version']);
        $disabled = empty($d['writable']) ? ' disabled' : '';

        // The script's words and the one-time cache notice; both stay unused (and hidden) without JavaScript.
        $words = $t['apply'] + ['reset_box' => $t['reset_box'], 'empty_value' => $t['empty_value']];
        $flush = '<div class="msg warn" id="settings-flush" role="status" hidden>' . self::e($t['flush_notice'])
            . ' <a class="btn" href="?p=ops">' . self::e($t['flush_link']) . '</a></div>';

        return $messages . $flush . '<form method="post" action="?p=settings" novalidate id="settings-form"'
            . ' data-apply="' . self::e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . '"'
            . ($disabled === '' ? '' : ' data-readonly="1"') . '>'
            . self::hidden('csrf', (string) $d['csrf']) . self::hidden('version', $version)
            . '<p class="note">' . self::e($t['intro']) . '</p>'
            . '<div class="controls"><input type="search" id="settings-filter" hidden placeholder="'
            . self::e($t['filter']) . '" aria-label="' . self::e($t['filter']) . '">'
            . '<label class="note" hidden id="settings-only-label"><input type="checkbox" id="settings-only"> '
            . self::e($t['apply']['only_changed']) . '</label></div>'
            . $sections . self::locked($t, $state) . $confirm
            . '<div class="actions bar-sticky"><span class="note pending" id="settings-pending" role="status" hidden></span>'
            . '<button class="btn" name="action" value="settings_check">' . self::e($t['check'])
            . '</button> <button class="btn primary" name="action" value="settings_save"' . $disabled . '>'
            . self::e($t['save']) . '</button></div></form>';
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, string> $form typed text by key (a re-render after a check or a failed save)
     * @param list<array<string, mixed>> $errors
     */
    private static function row(array $t, array $d, string $path, array $form, array $errors): string
    {
        $schema = Config::schema();
        $type = $schema[$path];
        $rule = (array) SettingRules::for($path);
        $state = $d['state'];
        $current = SettingsValidator::text($type, $state['effective'][$path]);
        $inherited = $state['inherited'][$path];
        $default = SettingsValidator::text($type, $state['defaults'][$path]);
        $source = array_key_exists($path, $state['stored']['values'])
            ? 'panel'
            : (array_key_exists($path, $state['file']) ? 'file' : 'default');
        $value = $form[$path] ?? $current;
        $changed = isset($form[$path]) && $form[$path] !== $current;
        $name = 'v[' . $path . ']';

        $control = match (true) {
            $type === 'bool' => self::hidden($name, '0') . '<label class="switch"><input type="checkbox" name="'
                . self::e($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . ' data-initial="'
                . self::e($current) . '" data-on="' . self::e($t['on']) . '" data-off="' . self::e($t['off']) . '"> <span>'
                . self::e($t[$value === '1' ? 'on' : 'off']) . '</span></label>',
            isset($rule['enum']) => self::select($name, $rule['enum'], $value, $current),
            $type === 'int' || $type === 'float' => '<input type="number" dir="ltr" name="' . self::e($name)
                . '" value="' . self::e($value) . '"' . (isset($rule['min']) ? ' min="' . $rule['min'] . '"' : '')
                . (isset($rule['max']) ? ' max="' . $rule['max'] . '"' : '') . ' step="'
                . ($rule['step'] ?? ($type === 'int' ? 1 : 'any')) . '" data-initial="' . self::e($current) . '">',
            ($rule['format'] ?? '') === 'csv' => '<textarea name="' . self::e($name)
                . '" rows="3" dir="auto" spellcheck="false" data-initial="' . self::e($current) . '">'
                . self::e(str_replace(',', "\n", $value)) . '</textarea>',
            default => '<input type="text" dir="ltr" name="' . self::e($name) . '" value="' . self::e($value)
                . '" maxlength="' . SettingRules::MAX_TEXT . '" data-initial="' . self::e($current) . '">',
        };
        $unit = isset($rule['unit'])
            ? ' <span class="note">' . self::e($t['units'][$rule['unit']] ?? '') . '</span>'
            : '';

        $extra = '';
        if ($source === 'panel') {
            $extra .= '<label class="note"><input type="checkbox" name="r[' . self::e($path) . ']" value="1"> '
                . self::e($t['reset_box']) . '</label>';
        }
        if ($type === 'string' && $inherited !== '') {
            $extra .= '<label class="note"><input type="checkbox" name="e[' . self::e($path) . ']" value="1"> '
                . self::e($t['empty_box']) . '</label>';
        }

        $problems = '';
        foreach ($errors as $error) {
            if (($error['key'] ?? '') === $path) {
                $problems .= '<div class="msg bad">' . self::e(self::describe($t['error_codes'], $error)) . '</div>';
            }
        }
        $badge = ['panel' => 'ok', 'file' => 'warn', 'default' => 'off'][$source];
        $shownCurrent = $current === '' ? $t['empty_value'] : $current;
        $shownDefault = $default === '' ? $t['empty_value'] : $default;
        $search = mb_strtolower($path . ' ' . ($t['help_keys'][$path] ?? ''), 'UTF-8');

        return '<tr class="srow' . ($changed ? ' dirty' : '') . '" data-key="' . self::e($path) . '" data-differs="'
            . ($current !== $default ? '1' : '0') . '" data-search="' . self::e($search) . '"><td>'
            . '<bdi class="ltr">' . self::e($path) . '</bdi><div class="note">'
            . self::e($t['help_keys'][$path] ?? '') . '</div>' . $problems . '</td><td>' . $control . $unit
            . $extra . '</td><td><span class="badge ' . $badge . '">' . self::e($t['source'][$source])
            . '</span> <span class="badge warn mark"' . ($changed ? '' : ' hidden') . '>' . self::e($t['changed'])
            . '</span><div class="note">' . self::e($t['current']) . ': <bdi class="ltr trunc">'
            . self::e($shownCurrent) . '</bdi></div><div class="note">' . self::e($t['default'])
            . ': <bdi class="ltr trunc">' . self::e($shownDefault) . '</bdi></div></td></tr>';
    }

    /** @param list<string|int> $options */
    private static function select(string $name, array $options, string $value, string $current): string
    {
        $html = '<select name="' . self::e($name) . '" dir="ltr" data-initial="' . self::e($current) . '">';
        foreach ($options as $option) {
            $html .= '<option value="' . self::e($option) . '"' . ((string) $option === $value ? ' selected' : '')
                . '>' . self::e($option) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param array<string, mixed> $state */
    private static function locked(array $t, array $state): string
    {
        $schema = Config::schema();
        $rows = '';
        foreach (array_keys($schema) as $path) {
            if (Config::editability($path) !== Config::READONLY) {
                continue;
            }
            $text = SettingsValidator::text($schema[$path], $state['effective'][$path]);
            $rows .= '<tr><td><bdi class="ltr">' . self::e($path) . '</bdi></td><td>'
                . ($text === '' ? '<span class="note">' . self::e($t['empty_value']) . '</span>'
                    : '<bdi class="ltr trunc">' . self::e($text) . '</bdi>')
                . '</td><td><span class="badge off">' . self::e($t['locked_badge']) . '</span></td></tr>';
        }

        return '<details class="card sgroup"><summary><strong>' . self::e($t['locked_title']) . '</strong></summary>'
            . '<p class="note">' . self::e($t['locked_note']) . '</p><div class="scroll"><table><tbody>' . $rows
            . '</tbody></table></div></details>';
    }

    /**
     * @param array<string, string> $codes
     * @param array<string, mixed> $item a validator error / warning / info entry
     */
    public static function describe(array $codes, array $item): string
    {
        $text = $codes[(string) $item['code']] ?? (string) $item['code'];

        return AdminStrings::fill($text, ...array_map('strval', (array) ($item['args'] ?? [])));
    }

    /** @param array<string, mixed> $result */
    private static function resultBox(array $t, array $result): string
    {
        $status = (string) $result['status'];
        $html = '';
        if ($status === 'rejected') {
            return '<div class="msg bad">' . self::e($t['rejected']) . '</div>';
        }
        if (isset($t['status'][$status])) {
            $html .= '<div class="msg bad">' . self::e($t['status'][$status]) . '</div>';
        }
        foreach (
            [
            ['errors', 'bad', 'error_codes', 'errors_title'],
            ['warnings', 'warn', 'warning_codes', 'warnings_title'],
            ['info', 'ok', 'info_codes', 'info_title'],
            ] as [$list, $kind, $codes, $title]
        ) {
            $items = (array) ($result[$list] ?? []);
            if ($items === []) {
                continue;
            }
            $lines = '';
            foreach ($items as $item) {
                $key = isset($item['key']) ? '<bdi class="ltr">' . self::e($item['key']) . '</bdi>: ' : '';
                $lines .= '<li>' . $key . self::e(self::describe($t[$codes], $item)) . '</li>';
            }
            $html .= '<div class="msg ' . $kind . '"><strong>' . self::e($t[$title]) . '</strong><ul>' . $lines
                . '</ul></div>';
        }
        if ($status === 'confirm') {
            $html .= '<p class="note">' . self::e($t['warnings_note']) . '</p>';
        }
        if (in_array($status, ['checked', 'confirm'], true)) {
            $html .= self::diffTable($t, (array) $result['changes']);
        }

        return $html;
    }

    /** @param list<array{key: string, from: string, to: string, reset: bool}> $changes */
    private static function diffTable(array $t, array $changes): string
    {
        if ($changes === []) {
            return '<div class="msg ok">' . self::e($t['no_changes']) . '</div>';
        }
        $rows = '';
        foreach ($changes as $change) {
            $show = static fn (string $text): string => $text === ''
                ? '<span class="note">' . self::e($t['empty_value']) . '</span>'
                : '<bdi class="ltr trunc">' . self::e($text) . '</bdi>';
            $rows .= '<tr><td><bdi class="ltr">' . self::e($change['key']) . '</bdi></td><td>' . $show($change['from'])
                . '</td><td>' . $show($change['to']) . ($change['reset']
                    ? ' <span class="badge off">' . self::e($t['diff_reset']) . '</span>' : '') . '</td></tr>';
        }

        return '<section class="card"><h2>' . self::e($t['diff_title']) . '</h2><div class="msg ok">'
            . self::e($t['checked']) . '</div><div class="scroll"><table><thead><tr><th>' . self::e($t['diff_key'])
            . '</th><th>' . self::e($t['diff_from']) . '</th><th>' . self::e($t['diff_to'])
            . '</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    /** @param array<string, mixed> $d */
    private static function history(array $t, array $d): string
    {
        $h = $t['history'];
        $version = (string) $d['state']['stored']['version'];
        $body = '';
        foreach ($d['backups'] as $backup) {
            $name = (string) $backup['name'];
            $restore = '<form method="post" action="?p=settings&amp;tab=history" class="inline" data-confirm="'
                . self::e($h['restore_confirm']) . '">' . self::hidden('csrf', (string) $d['csrf'])
                . self::hidden('action', 'settings_restore') . self::hidden('backup', $name)
                . self::hidden('version', $version) . '<button class="btn"'
                . (empty($d['writable']) ? ' disabled' : '') . '>' . self::e($h['restore']) . '</button></form>';
            $when = AdminViews::ts(AdminDate::fromUnix((int) $backup['time']));
            $body .= '<tr><td class="c">' . $when . '</td><td class="c">'
                . ($backup['keys'] === null ? self::e($h['unreadable']) : AdminViews::num($backup['keys']))
                . '</td><td class="c">' . AdminViews::num($backup['size'])
                . '</td><td><a class="btn" href="?p=settings&amp;tab=history&amp;diff='
                . rawurlencode($name) . '">' . self::e($h['diff']) . '</a> ' . $restore . '</td></tr>';
        }
        $table = $body === ''
            ? '<p class="note">' . self::e($h['empty']) . '</p>'
            : '<div class="scroll card"><table><thead><tr><th class="c">' . self::e($h['time']) . '</th><th class="c">'
                . self::e($h['keys']) . '</th><th class="c">' . self::e($h['size']) . '</th><th>'
                . self::e($h['actions'])
                . '</th></tr></thead><tbody>' . $body . '</tbody></table></div>';

        return ($d['diff'] ?? null) !== null ? self::diffCard($t, $d['diff']) . $table : $table;
    }

    /** @param array{name: string, rows: list<array{key: string, backup: ?string, current: ?string}>} $diff */
    private static function diffCard(array $t, array $diff): string
    {
        $h = $t['history'];
        if ($diff['rows'] === []) {
            $inner = '<p class="note">' . self::e($h['diff_none']) . '</p>';
        } else {
            $cell = static fn (?string $text): string => $text === null
                ? '<span class="note">' . self::e($h['absent']) . '</span>'
                : '<bdi class="ltr trunc">' . self::e($text === '' ? $t['empty_value'] : $text) . '</bdi>';
            $rows = '';
            foreach ($diff['rows'] as $row) {
                $rows .= '<tr><td><bdi class="ltr">' . self::e($row['key']) . '</bdi></td><td>' . $cell($row['backup'])
                    . '</td><td>' . $cell($row['current']) . '</td></tr>';
            }
            $inner = '<div class="scroll"><table><thead><tr><th>' . self::e($t['diff_key']) . '</th><th>'
                . self::e($h['backup_value']) . '</th><th>' . self::e($h['current_value']) . '</th></tr></thead><tbody>'
                . $rows . '</tbody></table></div>';
        }

        return '<section class="card"><h2>' . self::e(AdminStrings::fill($h['diff_title'], $diff['name'])) . '</h2>'
            . $inner . '</section><br>';
    }

    /**
     * Keys that differ between a backup and the current panel values, as display text (null = no panel value).
     *
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $current
     * @return list<array{key: string, backup: ?string, current: ?string}>
     */
    public static function diffRows(array $backup, array $current): array
    {
        $schema = Config::schema();
        $rows = [];
        foreach (AdminSettings::differing($backup, $current) as $key) {
            $rows[] = [
                'key' => $key,
                'backup' => isset($backup[$key]) ? SettingsValidator::text($schema[$key], $backup[$key]) : null,
                'current' => isset($current[$key]) ? SettingsValidator::text($schema[$key], $current[$key]) : null,
            ];
        }

        return $rows;
    }
}
