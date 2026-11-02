<?php

declare(strict_types=1);

namespace App;

/**
 * Pure CSV logic of the golden-set labeling helpers (tools/eval/make_candidates.php, finish_golden.php,
 * docs/EVALUATION.md): the grouped log export in, a candidate sheet for Excel out; the marked sheet in, the golden
 * CSV GoldenEval::parseCsv() reads out.
 */
final class GoldenLabeling
{
    public const CANDIDATE_COLUMNS = ['query', 'rank', 'product_id', 'title', 'relevant', 'note'];

    /**
     * The distinct queries of a grouped log export (column `normalized_q`, see LogsExport::GROUPED_COLUMNS), in file
     * order (most searched first), at most $n; queries that normalize equally count once.
     *
     * @return list<string>
     */
    public static function readQueries(string $csv, int $n): array
    {
        $rows = self::parse($csv);
        $header = array_shift($rows) ?? [];
        $names = array_map(static fn ($c): string => strtolower(trim((string) $c)), $header);
        $column = array_search('normalized_q', $names, true);
        if ($column === false) {
            throw new \InvalidArgumentException(
                'the queries file needs the column normalized_q (a --grouped log export)'
            );
        }
        $queries = [];
        $seen = [];
        foreach ($rows as $row) {
            $query = trim((string) ($row[$column] ?? ''));
            $key = Normalizer::normalize($query);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $queries[] = $query;
            if (count($queries) >= max(1, $n)) {
                break;
            }
        }

        return $queries;
    }

    /**
     * One row per result; a query with no result gets one placeholder row (empty rank and id) so it can still be
     * marked off-topic in the sheet.
     *
     * @param list<int> $ids ranked product ids, already cut to k
     * @param array<int, string> $titles
     * @return list<list<string>>
     */
    public static function candidateRows(string $query, array $ids, array $titles): array
    {
        if ($ids === []) {
            return [[$query, '', '', '(no results)', '', '']];
        }
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = [$query, (string) ($i + 1), (string) $id, $titles[$id] ?? '', '', ''];
        }

        return $rows;
    }

    /** @param list<list<string>> $rows UTF-8 with a BOM, which makes Excel read Persian text correctly */
    public static function candidatesCsv(array $rows): string
    {
        return "\xEF\xBB\xBF" . self::write(array_merge([self::CANDIDATE_COLUMNS], $rows));
    }

    /**
     * The marked candidate sheet as golden rows.
     *
     * `relevant` 1 marks a right product; 0 a wrong one; empty is unmarked. A query with a 1 gets those ids (in sheet
     * order). A query whose rows are all 0 and whose note cells hold the word "off" is an off-topic row (no ids). Any
     * other query is skipped and listed with the reason. Note columns are every column whose header starts with
     * "note"; their distinct texts are joined into the golden note.
     *
     * @return array{
     *     golden: list<array{query: string, relevant_ids: string, note: string}>,
     *     skipped: list<array{query: string, reason: string}>
     * }
     */
    public static function finish(string $csv): array
    {
        $rows = self::parse($csv);
        $header = array_map(static fn ($c): string => strtolower(trim((string) $c)), array_shift($rows) ?? []);
        $at = array_flip($header);
        foreach (['query', 'product_id', 'relevant'] as $name) {
            if (!isset($at[$name])) {
                throw new \InvalidArgumentException("the candidate sheet needs the column {$name}");
            }
        }
        $noteColumns = array_keys(array_filter($header, static fn (string $h): bool => str_starts_with($h, 'note')));

        $queries = [];
        foreach ($rows as $line => $row) {
            $query = trim((string) ($row[$at['query']] ?? ''));
            if ($query === '') {
                continue;
            }
            $mark = self::mark((string) ($row[$at['relevant']] ?? ''));
            if ($mark === false) {
                throw new \InvalidArgumentException(sprintf(
                    'row %d (%s): relevant must be 1, 0 or empty, got "%s"',
                    $line + 2,
                    $query,
                    trim((string) $row[$at['relevant']])
                ));
            }
            $queries[$query]['marks'][] = $mark;
            $id = trim((string) ($row[$at['product_id']] ?? ''));
            if ($mark === 1 && $id !== '') {
                $queries[$query]['ids'][] = (int) $id;
            }
            foreach ($noteColumns as $column) {
                $note = trim((string) ($row[$column] ?? ''));
                if ($note !== '') {
                    $queries[$query]['notes'][$note] = true;
                }
            }
        }

        $golden = [];
        $skipped = [];
        foreach ($queries as $query => $data) {
            $query = (string) $query;
            $marks = $data['marks'];
            $notes = array_map('strval', array_keys($data['notes'] ?? []));
            $note = implode(' | ', $notes);
            if (in_array(1, $marks, true)) {
                $ids = implode(';', array_values(array_unique($data['ids'] ?? [])));
                $golden[] = ['query' => $query, 'relevant_ids' => $ids, 'note' => $note];
            } elseif ($marks === array_fill(0, count($marks), 0)) {
                if (preg_match('/\boff\b/i', $note) === 1) {
                    $golden[] = ['query' => $query, 'relevant_ids' => '', 'note' => $note];
                } else {
                    $skipped[] = ['query' => $query, 'reason' => 'all rows 0 but no "off" in a note column'];
                }
            } elseif (array_filter($marks, static fn ($m): bool => $m !== null) === []) {
                $skipped[] = ['query' => $query, 'reason' => 'no mark'];
            } else {
                $skipped[] = ['query' => $query, 'reason' => 'no 1 and some rows left unmarked'];
            }
        }

        return ['golden' => $golden, 'skipped' => $skipped];
    }

    /** @param list<array{query: string, relevant_ids: string, note: string}> $golden */
    public static function goldenCsv(array $golden): string
    {
        return self::write(array_merge(
            [['query', 'relevant_ids', 'note']],
            array_map(static fn (array $g): array => [$g['query'], $g['relevant_ids'], $g['note']], $golden)
        ));
    }

    /** 1, 0, null (empty) or false (anything else). Persian and Arabic-Indic digits are accepted: Excel keeps them. */
    private static function mark(string $cell): int|false|null
    {
        $cell = strtr(trim($cell), ['۰' => '0', '۱' => '1', '٠' => '0', '١' => '1']);

        return match ($cell) {
            '' => null,
            '1' => 1,
            '0' => 0,
            default => false,
        };
    }

    /** @return list<list<string>> The delimiter is the one the header line uses: Excel saves ";" in some locales. */
    private static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $firstLine = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map('strval', $row);
            }
        }
        fclose($stream);

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private static function write(array $rows): string
    {
        $stream = fopen('php://memory', 'r+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '', "\n");
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
