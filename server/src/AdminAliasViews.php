<?php

declare(strict_types=1);

namespace App;

/**
 * The alias manager, synonyms viewer and search tester pages of the admin panel (M41). Pure HTML
 * builders over data the entry file collects; every value is escaped here, every sentence is in
 * {@see AdminStrings}.
 */
final class AdminAliasViews
{
    /** Rows of the synonyms viewer's table (its filter narrows it); the alias groups are paginated instead. */
    public const PAGE_ROWS = 200;
    /** Alias groups per page unless the request asks for another {@see Paging::SIZES} choice. */
    public const DEFAULT_PER = 50;

    private static function e(mixed $value): string
    {
        return AdminPage::esc((string) $value);
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    /** @param array<string, string> $fields */
    private static function hiddenAll(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            $html .= self::hidden($name, $value);
        }

        return $html;
    }

    /** A link styled as a button; $query is already URL-safe. */
    private static function button(string $query, string $label): string
    {
        return '<a class="btn" href="?' . $query . '">' . self::e($label) . '</a>';
    }

    /** A search box: a GET form back to the same page. */
    private static function searchForm(string $page, string $field, string $value, string $label, string $go): string
    {
        return '<form method="get" class="controls">' . self::hidden('p', $page)
            . '<input type="search" name="' . $field . '" value="' . self::e($value) . '" placeholder="'
            . self::e($label) . '" aria-label="' . self::e($label) . '" dir="auto"> <button class="btn">'
            . self::e($go) . '</button></form>';
    }

    /** @param array<string, mixed> $error a validator error or warning with its context */
    private static function describe(array $t, string $kind, array $error): string
    {
        $codes = $t[$kind === 'error' ? 'error_codes' : 'warning_codes'];
        $text = $codes[(string) $error['code']] ?? (string) $error['code'];
        $where = isset($error['group'])
            ? AdminStrings::fill($t['group_word'], AdminViews::num((int) $error['group'] + 1))
            : '';
        $sep = AdminStrings::fa()['ui']['list_sep'];
        $merged = is_array($error['merged'] ?? null) ? implode($sep, $error['merged']) : '';

        return AdminStrings::fill(
            $text,
            $where,
            (string) ($error['term'] ?? $error['max'] ?? ''),
            (string) ($error['max'] ?? $error['matches'] ?? $merged),
            (string) ($error['limit'] ?? '')
        );
    }

    /** @param list<array<string, mixed>> $items */
    private static function messages(array $t, string $kind, string $title, array $items): string
    {
        if ($items === []) {
            return '';
        }
        $list = '';
        foreach ($items as $item) {
            $list .= '<li>' . self::e(self::describe($t, $kind, $item)) . '</li>';
        }

        return '<div class="msg ' . ($kind === 'error' ? 'bad' : 'warn') . '"><strong>' . self::e($title)
            . '</strong><ul>' . $list . '</ul></div>';
    }

    /** The results of the last action, as messages above the page. @param array<string, mixed> $result */
    private static function resultBox(array $t, array $result): string
    {
        $status = (string) ($result['status'] ?? '');
        $html = '';
        if ($status === 'saved' || $status === 'restored') {
            return '<div class="msg ok">' . self::e($t[$status === 'saved' ? 'saved' : 'restored']) . '</div>';
        }
        if ($status === 'invalid') {
            $html .= self::messages($t, 'error', $t['errors_title'], $result['errors'] ?? []);
        } elseif ($status === 'confirm') {
            $html .= self::messages($t, 'warning', $t['warnings_title'], $result['warnings'] ?? [])
                . '<p class="note">' . self::e($t['warnings_note']) . '</p>';
        } elseif (isset($t['status'][$status])) {
            $html .= '<div class="msg bad">' . self::e($t['status'][$status]) . '</div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $d tab, csrf, state {groups, version, valid, raw, seeded}, q, edit, form,
     *        result, counts, backups, diff, raw_text, writable
     */
    public static function aliases(array $d): string
    {
        $t = AdminStrings::fa()['aliases'];
        $tab = in_array($d['tab'], ['groups', 'history', 'advanced'], true) ? $d['tab'] : 'groups';
        $tabs = '';
        foreach ($t['tabs'] as $key => $label) {
            $current = $key === $tab ? ' aria-current="page"' : '';
            $tabs .= '<a href="?p=aliases&amp;tab=' . $key . '"' . $current . '>' . self::e($label) . '</a>';
        }
        $head = AdminViews::helpBox($t['help']) . '<nav class="tabs">' . $tabs . '</nav>';
        if (($d['result'] ?? null) !== null) {
            $head .= self::resultBox($t, $d['result']);
        } elseif (!empty($d['saved'])) {
            $head .= self::resultBox($t, ['status' => (string) $d['saved']]);
        }
        if (!empty($d['state']['seeded'])) {
            $head .= '<div class="msg ok">' . self::e($t['seeded']) . '</div>';
        }
        if (empty($d['writable'])) {
            $head .= '<div class="msg bad">' . self::e($t['storage_unwritable']) . '</div>';
        }
        if (!$d['state']['valid']) {
            $head .= '<div class="msg bad">' . self::e($t['invalid_file']) . '</div>';
        }

        return $head . match ($tab) {
            'history' => self::history($t, $d),
            'advanced' => self::advanced($t, $d),
            default => self::groups($t, $d),
        };
    }

    /**
     * The groups table's address for one list state (search, page size, page); $extra adds the page's own
     * parameters (an edit, a count). Escaped for an attribute.
     *
     * @param array{q?: string, per?: int, page?: int} $list
     * @param array<string, string|int> $extra
     */
    public static function listUrl(array $list, array $extra = []): string
    {
        $query = ['p' => 'aliases', 'tab' => 'groups'] + $extra;
        if (($list['q'] ?? '') !== '') {
            $query['q'] = $list['q'];
        }
        if (($list['per'] ?? self::DEFAULT_PER) !== self::DEFAULT_PER) {
            $query['per'] = $list['per'];
        }
        if (($list['page'] ?? 1) > 1) {
            $query['page'] = $list['page'];
        }

        return '?' . self::e(http_build_query($query));
    }

    /**
     * The list state as hidden form fields, so a save or a delete comes back to the same page of the same search.
     *
     * @param array{q?: string, per?: int, page?: int} $list
     */
    private static function listFields(array $list): string
    {
        return self::hiddenAll([
            'q' => (string) ($list['q'] ?? ''),
            'per' => (string) ($list['per'] ?? self::DEFAULT_PER),
            'page' => (string) ($list['page'] ?? 1),
        ]);
    }

    /**
     * @param array<string, mixed> $d list {q, per, page}: the search and the page, as requested (clamped here)
     */
    private static function groups(array $t, array $d): string
    {
        $groups = $d['state']['groups'];
        $version = (string) $d['state']['version'];
        $merged = Synonyms::mergeOverlapping($groups);
        $requested = $d['list'] ?? ['q' => (string) ($d['q'] ?? '')];
        $needle = trim((string) ($requested['q'] ?? ''));
        $phrase = $needle === '' ? '' : AliasValidator::phrase($needle);

        // The search covers every group; only then is the result cut into pages.
        $found = [];
        foreach ($groups as $i => $group) {
            if ($needle === '' || self::groupHas($group, $needle, $phrase)) {
                $found[] = $i;
            }
        }
        $per = Paging::size($requested['per'] ?? null, self::DEFAULT_PER);
        $paging = Paging::of(count($found), (int) ($requested['page'] ?? 1), $per);
        $list = ['q' => $needle, 'per' => $per, 'page' => $paging['page']];
        $d['list'] = $list;

        $rows = '';
        foreach (array_slice($found, $paging['offset'], $paging['limit']) as $i) {
            $rows .= self::groupRow($t, $d, $i, $groups[$i], $merged, $version);
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">' . self::e($needle === '' ? $t['no_groups'] : $t['no_match']) . '</td></tr>';
        }

        $count = $needle === ''
            ? AdminStrings::fill($t['group_count'], AdminViews::num(count($groups)))
            : AdminStrings::fill($t['group_found'], AdminViews::num(count($found)), AdminViews::num(count($groups)));
        $search = '<form method="get" class="controls">' . self::hidden('p', 'aliases')
            . ($per === self::DEFAULT_PER ? '' : self::hidden('per', (string) $per))
            . '<input type="search" name="q" value="' . self::e($needle) . '" placeholder="' . self::e($t['search'])
            . '" aria-label="' . self::e($t['search']) . '" dir="auto"> <button class="btn">'
            . self::e($t['search_go']) . '</button> <span class="note">' . self::e($count) . '</span> '
            . self::button(substr(self::listUrl($list, ['edit' => 'new']), 1), $t['new_group']) . '</form>';
        $counts = ($d['counts'] ?? null) !== null ? self::countsCard($t, $d['counts']) : '';
        $pager = AdminViews::pager(
            $paging,
            static fn (int $page): string => self::listUrl(['page' => $page] + $list),
            AdminViews::perPage(
                ['p' => 'aliases', 'tab' => 'groups'] + ($needle === '' ? [] : ['q' => $needle]),
                $per,
                Paging::sizes(self::DEFAULT_PER)
            )
        );

        return $counts . self::editor($t, $d, $version) . $search . $pager . self::table($t, $rows) . $pager;
    }

    /** @param list<string> $group */
    private static function groupHas(array $group, string $needle, string $phrase): bool
    {
        foreach ($group as $term) {
            $byText = mb_stripos($term, $needle) !== false;
            if ($byText || ($phrase !== '' && str_contains(AliasValidator::phrase($term), $phrase))) {
                return true;
            }
        }

        return false;
    }

    private static function table(array $t, string $rows): string
    {
        return '<div class="scroll card"><table class="groups"><thead><tr><th class="c">#</th><th>'
            . self::e($t['tabs']['groups']) . '</th><th>' . self::e($t['effective'])
            . '</th><th class="act"></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param list<string> $group
     * @param list<list<string>> $merged
     */
    private static function groupRow(array $t, array $d, int $i, array $group, array $merged, string $version): string
    {
        $chips = '';
        foreach ($group as $term) {
            $normal = AliasValidator::phrase($term);
            $shownNormal = $normal !== '' && $normal !== $term
                ? ' <small class="note" title="' . self::e($t['normalized']) . '"><bdi class="ltr">'
                    . self::e($normal) . '</bdi></small>'
                : '';
            $chips .= '<span class="chip">' . self::e($term) . $shownNormal . '</span> ';
        }
        $phrases = array_map([AliasValidator::class, 'phrase'], $group);
        $effective = '';
        foreach ($merged as $mergedGroup) {
            $overlaps = array_intersect($mergedGroup, $phrases) !== [];
            if ($overlaps && count($mergedGroup) > count(array_unique($phrases))) {
                $sep = AdminStrings::fa()['ui']['list_sep'];
                $effective = '<span class="note">' . self::e(implode($sep, $mergedGroup)) . '</span>';
                break;
            }
        }
        $first = $group[0] ?? '';
        $delete = '<form method="post" action="?p=aliases" class="inline" data-confirm="'
            . self::e($t['delete_confirm']) . '">' . self::hiddenAll([
                'csrf' => (string) $d['csrf'], 'action' => 'alias_delete',
                'index' => (string) $i, 'version' => $version,
            ]) . self::listFields($d['list']) . '<button class="btn danger">' . self::e($t['delete'])
            . '</button></form>';
        $actions = self::button(substr(self::listUrl($d['list'], ['edit' => $i]), 1), $t['edit']) . ' '
            . self::button(substr(self::listUrl($d['list'], ['count' => $i]), 1), $t['count']) . ' '
            . self::button('p=tester&amp;q=' . rawurlencode($first), $t['test']) . ' ' . $delete;

        return '<tr><td class="c">' . AdminViews::num($i + 1) . '</td><td>' . $chips . '</td><td class="wrap">'
            . $effective . '</td><td class="act">' . $actions . '</td></tr>';
    }

    /** @param array{index: int, rows: list<array{term: string, count: int}>, cap: int} $counts */
    private static function countsCard(array $t, array $counts): string
    {
        $items = '';
        foreach ($counts['rows'] as $row) {
            $text = $row['count'] > $counts['cap']
                ? AdminStrings::fill($t['matches_more'], AdminViews::num($counts['cap']))
                : AdminViews::num($row['count']) . ' ' . $t['matches'];
            $items .= '<li>' . self::e($row['term']) . ': <strong>' . self::e($text) . '</strong></li>';
        }

        $title = AdminStrings::fill($t['counts_title'], AdminViews::num($counts['index'] + 1));

        return '<section class="card"><h2>' . self::e($title) . '</h2><ul>' . $items . '</ul></section><br>';
    }

    /** @param array<string, mixed> $d */
    private static function editor(array $t, array $d, string $version): string
    {
        $form = $d['form'] ?? null;
        $edit = $d['edit'] ?? null;
        if ($form === null && $edit === null) {
            return '';
        }
        $index = (string) ($form['index'] ?? ($edit === 'new' ? '' : $edit));
        $stored = $edit !== null && $edit !== 'new' ? implode("\n", $d['state']['groups'][(int) $edit] ?? []) : '';
        $terms = (string) ($form['terms'] ?? ($stored !== '' ? $stored : (string) ($d['prefill'] ?? '')));
        $confirm = ($d['result']['status'] ?? '') === 'confirm';
        $title = $index === ''
            ? $t['new_group']
            : AdminStrings::fill($t['edit_group'], AdminViews::num((int) $index + 1));
        $fields = [
            'csrf' => (string) $d['csrf'], 'action' => 'alias_save', 'index' => $index,
            'version' => (string) ($form['version'] ?? $version),
        ] + ($confirm ? ['confirm' => '1'] : []);

        return '<section class="card"><h2>' . self::e($title) . '</h2><form method="post" action="?p=aliases">'
            . self::hiddenAll($fields) . self::listFields($d['list']) . '<label>' . self::e($t['terms_label'])
            . '<br><textarea name="terms" rows="6" cols="48" dir="auto">' . self::e($terms)
            . '</textarea></label><p class="note">' . self::e($t['terms_hint']) . '</p><div class="actions">'
            . '<button class="btn primary">' . self::e($confirm ? $t['save_anyway'] : $t['save']) . '</button> '
            . self::button(substr(self::listUrl($d['list']), 1), $t['cancel']) . '</div></form></section><br>';
    }

    /** @param array<string, mixed> $d */
    private static function advanced(array $t, array $d): string
    {
        $a = $t['advanced'];
        $text = (string) ($d['raw_text'] ?? AliasStore::encode($d['state']['groups']));
        $confirm = ($d['result']['status'] ?? '') === 'confirm';
        $version = (string) ($d['form']['version'] ?? $d['state']['version']);

        $fields = ['csrf' => (string) $d['csrf'], 'action' => 'alias_raw', 'version' => $version]
            + ($confirm ? ['confirm' => '1'] : []);

        return '<section class="card"><form method="post" action="?p=aliases&amp;tab=advanced">'
            . self::hiddenAll($fields) . '<label>' . self::e($a['label'])
            . '<br><textarea name="json" rows="22" cols="80" dir="ltr" spellcheck="false">' . self::e($text)
            . '</textarea></label><p class="note">' . self::e($a['note']) . '</p><div class="actions">'
            . '<button class="btn primary">' . self::e($confirm ? $t['save_anyway'] : $a['save']) . '</button> '
            . self::button('p=aliases&amp;download=1', $a['download']) . '</div></form></section>';
    }

    /** @param array<string, mixed> $d */
    private static function history(array $t, array $d): string
    {
        $h = $t['history'];
        $version = (string) $d['state']['version'];
        $body = '';
        foreach ($d['backups'] as $backup) {
            $name = (string) $backup['name'];
            $groupsCell = $backup['groups'] === null ? self::e($h['unreadable']) : AdminViews::num($backup['groups']);
            $restore = '<form method="post" action="?p=aliases&amp;tab=history" class="inline" data-confirm="'
                . self::e($h['restore_confirm']) . '">' . self::hiddenAll([
                    'csrf' => (string) $d['csrf'], 'action' => 'alias_restore',
                    'backup' => $name, 'version' => $version,
                ]) . '<button class="btn">' . self::e($h['restore']) . '</button></form>';
            $when = AdminViews::ts(AdminDate::fromUnix((int) $backup['time']));
            $body .= '<tr><td class="c">' . $when . '</td><td class="c">'
                . $groupsCell . '</td><td class="c">' . AdminViews::num($backup['size']) . '</td><td>'
                . self::button('p=aliases&amp;tab=history&amp;diff=' . rawurlencode($name), $h['diff']) . ' '
                . $restore . '</td></tr>';
        }
        $table = $body === ''
            ? '<p class="note">' . self::e($h['empty']) . '</p>'
            : '<div class="scroll card"><table><thead><tr><th class="c">' . self::e($h['time']) . '</th><th class="c">'
                . self::e($h['groups']) . '</th><th class="c">' . self::e($h['size']) . '</th><th>'
                . self::e($h['actions']) . '</th></tr></thead><tbody>' . $body . '</tbody></table></div>';

        return ($d['diff'] ?? null) !== null ? self::diffCard($h, $d['diff']) . $table : $table;
    }

    /** @param array{name: string, removed: list<string>, added: list<string>} $diff */
    private static function diffCard(array $h, array $diff): string
    {
        if ($diff['removed'] === [] && $diff['added'] === []) {
            $inner = '<p class="note">' . self::e($h['diff_none']) . '</p>';
        } else {
            $inner = '';
            foreach (['removed' => 'bad', 'added' => 'ok'] as $key => $kind) {
                if ($diff[$key] === []) {
                    continue;
                }
                $inner .= '<h3>' . self::e($h[$key]) . '</h3><ul class="difflist">';
                foreach ($diff[$key] as $line) {
                    $inner .= '<li class="' . $kind . '"><bdi class="ltr">' . self::e($line) . '</bdi></li>';
                }
                $inner .= '</ul>';
            }
        }

        return '<section class="card"><h2>' . self::e(AdminStrings::fill($h['diff_title'], $diff['name'])) . '</h2>'
            . $inner . '</section><br>';
    }

    /**
     * Groups present in only one of two files, as one line per group. A line diff on the groups
     * (not on the JSON text) keeps a reordered or reformatted file from looking like a rewrite.
     *
     * @param list<list<string>> $backup
     * @param list<list<string>> $current
     * @return array{removed: list<string>, added: list<string>}
     */
    public static function groupDiff(array $backup, array $current): array
    {
        $line = static fn (array $group): string => implode(' | ', $group);
        $old = array_map($line, $backup);
        $new = array_map($line, $current);

        return ['removed' => array_values(array_diff($old, $new)), 'added' => array_values(array_diff($new, $old))];
    }

    /**
     * @param array<string, mixed> $d term, groups (the stored alias groups), synonyms (list of groups|null),
     *        csrf, filter
     */
    public static function synonyms(array $d): string
    {
        $t = AdminStrings::fa()['synonyms'];
        $html = AdminViews::helpBox($t['help']) . '<p class="note">' . self::e($t['blocklist_note']) . '</p>';
        $term = trim((string) ($d['term'] ?? ''));
        $html .= self::searchForm('synonyms', 'term', $term, $t['term_label'], $t['term_go']);

        if ($d['synonyms'] === null) {
            return $html . '<div class="msg bad">' . self::e($t['missing']) . '</div>';
        }
        if ($term !== '') {
            $html .= self::termLookup($t, $term, $d['groups'], $d['synonyms']);
        }

        $filter = trim((string) ($d['filter'] ?? ''));
        $all = $d['synonyms'];
        $rows = '';
        $shown = 0;
        $phrase = $filter === '' ? '' : AliasValidator::phrase($filter);
        foreach ($all as $group) {
            if ($filter !== '' && !self::groupHas($group, $filter, $phrase)) {
                continue;
            }
            if ($shown++ >= self::PAGE_ROWS) {
                break;
            }
            $rows .= '<tr><td>' . self::e(implode(AdminStrings::fa()['ui']['list_sep'], $group)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td>' . self::e($t['empty']) . '</td></tr>';
        }

        $shownText = AdminStrings::fill($t['shown'], AdminViews::num($shown), AdminViews::num(count($all)));
        $heading = AdminStrings::fill($t['groups_title'], AdminViews::num(count($all)));

        return $html . self::searchForm('synonyms', 'filter', $filter, $t['filter'], $t['term_go'])
            . '<p class="note">' . self::e($shownText) . '</p><div class="scroll card"><table><thead><tr><th>'
            . self::e($heading) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param list<list<string>> $aliasGroups
     * @param list<list<string>> $synonymGroups
     */
    private static function termLookup(array $t, string $term, array $aliasGroups, array $synonymGroups): string
    {
        $phrase = AliasValidator::phrase($term);
        $hasTerm = static fn (array $group): bool => in_array(
            $phrase,
            array_map([AliasValidator::class, 'phrase'], $group),
            true
        );
        $inAliases = array_values(array_filter($aliasGroups, $hasTerm));
        $inSynonyms = array_values(array_filter($synonymGroups, $hasTerm));
        $card = static function (string $title, array $groups): string {
            $items = '';
            foreach ($groups as $group) {
                $items .= '<li>' . self::e(implode(AdminStrings::fa()['ui']['list_sep'], $group)) . '</li>';
            }

            return '<h3>' . self::e($title) . '</h3><ul>' . $items . '</ul>';
        };

        $body = '';
        if ($inAliases === [] && $inSynonyms === []) {
            $body = '<p class="note">' . self::e($t['none_found']) . '</p>';
        } else {
            $effective = Synonyms::mergeOverlapping($aliasGroups);
            $effective = array_values(array_filter($effective, $hasTerm));
            $body = ($inAliases !== [] ? $card($t['alias_groups'], $inAliases) : '')
                . ($inSynonyms !== [] ? $card($t['synonym_groups'], $inSynonyms) : '')
                . ($effective !== [] ? $card($t['effective'], $effective) : '')
                . '<p>' . self::button(
                    'p=aliases&amp;tab=groups&amp;edit=new&amp;prefill='
                        . rawurlencode(implode("\n", $inSynonyms[0] ?? $inAliases[0] ?? [$term])),
                    $t['copy']
                ) . '</p>';
        }

        return '<section class="card">' . $body . '</section><br>';
    }

    /**
     * @param array<string, mixed> $d q, result (search response + debug) | null, titles (id => title),
     *        error (bool)
     */
    public static function tester(array $d): string
    {
        $t = AdminStrings::fa()['tester'];
        $q = (string) ($d['q'] ?? '');
        $html = AdminViews::helpBox($t['help']) . '<p class="note">' . self::e($t['note']) . '</p>'
            . self::searchForm('tester', 'q', $q, $t['query'], $t['go']);
        if (!empty($d['error'])) {
            return $html . '<div class="msg bad">' . self::e($t['failed']) . '</div>';
        }
        $r = $d['result'] ?? null;
        if ($r === null) {
            $hint = $q === '' && !empty($d['asked']) ? '<p class="note">' . self::e($t['empty_query']) . '</p>' : '';

            return $html . $hint;
        }

        $tier = (string) ($r['debug']['tier'] ?? '');
        $suggestion = $r['did_you_mean'] === null
            ? self::e(AdminStrings::fa()['ui']['none'])
            : self::e($r['did_you_mean']) . ($r['did_you_mean_applied'] ? ' ' . self::e($t['applied']) : '');
        $meta = '<dl class="kv"><dt>' . self::e($t['normalized']) . '</dt><dd><bdi class="ltr">'
            . self::e($r['query']['normalized']) . '</bdi></dd><dt>' . self::e($t['tier']) . '</dt><dd>'
            . self::e($t['tiers'][$tier] ?? $tier) . '</dd><dt>' . self::e($t['did_you_mean']) . '</dt><dd>'
            . $suggestion . '</dd><dt>' . self::e($t['count']) . '</dt><dd>' . AdminViews::num($r['count'])
            . '</dd></dl>';

        $rows = '';
        foreach (array_slice($r['debug']['results'] ?? [], 0, 10) as $row) {
            $hit = $row['keyword_hit'];
            $type = $hit === null ? 'semantic' : (string) $hit['match_type'];
            $coverage = $hit === null ? '—' : self::e(number_format((float) $hit['coverage'], 2, '.', ''));
            $rows .= '<tr><td class="c">' . AdminViews::num($row['rank']) . '</td><td class="c">'
                . AdminViews::num($row['product_id']) . '</td><td dir="auto">'
                . self::e($d['titles'][$row['product_id']] ?? '') . '</td><td class="c">'
                . self::e($t['match_types'][$type] ?? $type) . '</td><td class="c">' . $coverage . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">' . self::e($t['no_results']) . '</td></tr>';
        }

        return $html . '<section class="card">' . $meta . '</section><br><div class="scroll card"><table>'
            . '<thead><tr><th class="c">' . self::e($t['rank']) . '</th><th class="c">' . self::e($t['id'])
            . '</th><th>' . self::e($t['product']) . '</th><th class="c">' . self::e($t['match'])
            . '</th><th class="c">'
            . self::e($t['coverage']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
