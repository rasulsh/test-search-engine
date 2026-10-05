<?php

declare(strict_types=1);

namespace App;

/**
 * Query-side synonym and alias expansion for the keyword tier (M15).
 *
 * Two bundle files, same format (a JSON list of groups, each a list of
 * equivalent terms):
 *  - synonyms.json, generated from the catalog by pipeline/keyword.py;
 *  - aliases.json, the shop owner's hand-maintained product-name variants
 *    ("gta" = "grand theft auto" = "جی تی ای"), shipped with the bundle.
 *
 * A term may be a phrase. Terms are matched against the query as whole token
 * sequences, never as substrings, so "gta" never rewrites "gtax". Each match
 * yields a variant query with that span replaced by another term of its group;
 * {@see Keyword} searches every variant and merges the hits. Terms are
 * normalized here too (idempotent on the pipeline's output), so an entry edited
 * by hand on the server still matches normalized queries.
 *
 * Generated groups larger than $maxSynonymGroupSize are ignored: a catalog link
 * that joins many labels is more likely noise (a shared junk model code) than a
 * real synonym, and would pull in unrelated products. Owner aliases are
 * explicit and never capped.
 */
final class Synonyms
{
    public const SYNONYMS_FILE = 'synonyms.json';
    public const ALIASES_FILE = 'aliases.json';

    /** @var array<string, self> keyed by the files' identity and the group cap */
    private static array $cache = [];

    /** @var array<string, list<list<string>>> phrase (tokens joined by " ") => alternative token lists */
    private array $alternatives = [];
    private int $longestPhrase = 0;

    /** @param list<list<string>> $groups */
    public function __construct(array $groups)
    {
        foreach ($groups as $group) {
            $terms = [];
            foreach ($group as $term) {
                $tokens = Tokenizer::split(Normalizer::normalize($term));
                if ($tokens !== []) {
                    $terms[implode(' ', $tokens)] = $tokens;
                }
            }
            foreach ($terms as $phrase => $tokens) {
                foreach ($terms as $otherPhrase => $otherTokens) {
                    if ($otherPhrase !== $phrase && !in_array($otherTokens, $this->alternatives[$phrase] ?? [], true)) {
                        $this->alternatives[$phrase][] = $otherTokens;
                    }
                }
                $this->longestPhrase = max($this->longestPhrase, count($tokens));
            }
        }
    }

    /**
     * Synonyms and aliases of the bundle in $dataDir; absent files contribute
     * nothing and an unreadable one is skipped (search must never fail on it —
     * /reload validates the files before they go live). Cached per worker,
     * keyed by the files' identity, so the reload swap is picked up unaided.
     */
    public static function fromDirectory(string $dataDir, int $maxSynonymGroupSize): self
    {
        $synonymsPath = $dataDir . '/' . self::SYNONYMS_FILE;
        $aliasesPath = $dataDir . '/' . self::ALIASES_FILE;
        $key = self::identity($synonymsPath) . '|' . self::identity($aliasesPath) . '|' . $maxSynonymGroupSize;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $synonyms = array_values(array_filter(
            self::readGroups($synonymsPath) ?? [],
            static fn (array $group): bool => count($group) <= $maxSynonymGroupSize
        ));
        self::$cache = []; // only the live bundle's entry is worth keeping

        return self::$cache[$key] = new self(array_merge($synonyms, self::readGroups($aliasesPath) ?? []));
    }

    /** Drop cached maps (POST /reload, after the swap). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Parse a groups file: a JSON list of lists of strings. Null when the JSON
     * or its shape is invalid; the caller decides whether that is fatal.
     *
     * @return list<list<string>>|null
     */
    public static function parseGroups(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        foreach ($data as $group) {
            if (!is_array($group) || !array_is_list($group)) {
                return null;
            }
            foreach ($group as $term) {
                if (!is_string($term)) {
                    return null;
                }
            }
        }

        return $data;
    }

    /**
     * The query's tokens first, then one variant per (matched span, other term
     * of its group), then, when several spans matched, the variants with every
     * matched span swapped at once (a query naming two aliased terms is only
     * found whole in a product listed under both other names), at most $max in
     * total. Spans are found left to right, longest phrase first, and never
     * overlap.
     *
     * @param list<string> $tokens normalized query tokens
     * @return non-empty-list<list<string>>
     */
    public function variants(array $tokens, int $max): array
    {
        $spans = [];
        $count = count($tokens);
        for ($start = 0; $start < $count;) {
            $length = min($this->longestPhrase, $count - $start);
            for (; $length > 0; $length--) {
                $phrase = implode(' ', array_slice($tokens, $start, $length));
                if (isset($this->alternatives[$phrase])) {
                    break;
                }
            }
            if ($length === 0) {
                $start++;
                continue;
            }
            $spans[] = [$start, $length, $this->alternatives[$phrase]];
            $start += $length;
        }

        $variants = [$tokens];
        $add = static function (array $variant) use (&$variants, $max): void {
            if (count($variants) < $max && !in_array($variant, $variants, true)) {
                $variants[] = $variant;
            }
        };
        foreach ($spans as [$start, $length, $alternatives]) {
            foreach ($alternatives as $alternative) {
                $add(self::swap($tokens, [[$start, $length, $alternative]]));
            }
        }
        if (count($spans) > 1) {
            // Every span swapped: the cross product of the groups' other terms,
            // enumerated like an odometer (last span fastest) until the cap.
            $choice = array_fill(0, count($spans), 0);
            for (; count($variants) < $max;) {
                $swaps = [];
                foreach ($spans as $i => [$start, $length, $alternatives]) {
                    $swaps[] = [$start, $length, $alternatives[$choice[$i]]];
                }
                $add(self::swap($tokens, $swaps));
                for ($i = count($spans) - 1; $i >= 0 && ++$choice[$i] === count($spans[$i][2]); $i--) {
                    $choice[$i] = 0;
                }
                if ($i < 0) {
                    break;
                }
            }
        }

        return $variants;
    }

    /**
     * @param list<string> $tokens
     * @param list<array{0: int, 1: int, 2: list<string>}> $swaps [start, length, replacement], ascending
     * @return list<string>
     */
    private static function swap(array $tokens, array $swaps): array
    {
        $result = [];
        $position = 0;
        foreach ($swaps as [$start, $length, $replacement]) {
            array_push($result, ...array_slice($tokens, $position, $start - $position), ...$replacement);
            $position = $start + $length;
        }
        array_push($result, ...array_slice($tokens, $position));

        return $result;
    }

    /** @return list<list<string>>|null */
    private static function readGroups(string $path): ?array
    {
        $json = is_file($path) ? @file_get_contents($path) : false;

        return $json === false ? null : self::parseGroups($json);
    }

    private static function identity(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? '-' : $path . ':' . $stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'];
    }
}
