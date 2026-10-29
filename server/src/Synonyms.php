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
 * sequences, never as substrings, so "gta" never rewrites "gtax". The one exception (M43) is a
 * letter-digit model name: "gta6" also meets an alias term written "gta 6", and "gta 6" one written
 * "gta6", so the owner lists either spelling once. Each match
 * yields a variant query with that span replaced by another term of its group;
 * {@see Keyword} searches every variant and merges the hits. Terms are
 * normalized here too (idempotent on the pipeline's output), so an entry edited
 * by hand on the server still matches normalized queries.
 *
 * Generated groups larger than $maxSynonymGroupSize are ignored: a catalog link
 * that joins many labels is more likely noise (a shared junk model code) than a
 * real synonym, and would pull in unrelated products. Owner aliases are
 * explicit and never capped.
 *
 * A generated group holding a term of `search.synonym_blocklist` is dropped (M43): the way to remove a
 * bad group the build derived, since a rebuild would bring back an edited synonyms.json. Owner aliases
 * are never filtered.
 */
final class Synonyms
{
    public const SYNONYMS_FILE = 'synonyms.json';
    public const ALIASES_FILE = 'aliases.json';
    /** A letter-digit model name ("gta6", "rtx4060"): the only shape that also meets its spaced spelling (M43). */
    public const LETTER_DIGIT = '/^([a-z]{2,5})(\d{1,4})$/';

    /** @var array<string, self> keyed by the files' content hashes and the group cap */
    private static array $cache = [];

    /** @var array<string, list<list<string>>> phrase (tokens joined by " ") => alternative token lists */
    private array $alternatives = [];
    /** @var array<string, string> collapsed phrase => phrase, to meet a spacing variant */
    private array $byCollapsed = [];
    private int $longestPhrase = 0;
    private string $version;
    /** @var list<string> */
    private array $aliasTokens;
    /** @var array<string, true> */
    private array $aliasTokenSet;

    /**
     * @param list<list<string>> $groups
     * @param string $version identifies the files the groups came from (see {@see version()})
     * @param list<string> $aliasTokens every token of the owner's alias groups ({@see aliasTokens()})
     */
    public function __construct(array $groups, string $version = '', array $aliasTokens = [])
    {
        $this->version = $version;
        $this->aliasTokens = $aliasTokens;
        $this->aliasTokenSet = array_fill_keys($aliasTokens, true);
        foreach ($groups as $group) {
            $terms = [];
            foreach ($group as $term) {
                $tokens = Tokenizer::split(Normalizer::normalize($term));
                if ($tokens !== []) {
                    $terms[implode(' ', $tokens)] = $tokens;
                }
            }
            foreach ($terms as $phrase => $tokens) {
                $this->byCollapsed[Normalizer::collapse($phrase)] ??= (string) $phrase;
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
     * keyed by the files' content hashes, so the reload swap is picked up unaided.
     *
     * The owner's aliases come from $aliasesPath (the server's storage file, M41); the default
     * stays the bundle's own `aliases.json` for an install that has not been seeded yet.
     *
     * @param string $blocklist `search.synonym_blocklist`: comma separated terms; a generated group
     *        holding one of them (normalized) is dropped
     */
    public static function fromDirectory(
        string $dataDir,
        int $maxSynonymGroupSize,
        ?string $aliasesPath = null,
        string $blocklist = ''
    ): self {
        $synonymsPath = $dataDir . '/' . self::SYNONYMS_FILE;
        $aliasesPath ??= $dataDir . '/' . self::ALIASES_FILE;
        // Content hashes, not stat identities: the key is also part of the result-cache
        // key, so it must change exactly when an answer could, an edit that keeps the size
        // and lands in the same mtime tick included. Both files are small next to a query.
        $blocked = self::blockedPhrases($blocklist);
        $key = (string) @sha1_file($synonymsPath) . '|' . (string) @sha1_file($aliasesPath)
            . '|' . $maxSynonymGroupSize . '|' . implode(',', $blocked);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $synonyms = array_values(array_filter(
            self::readGroups($synonymsPath) ?? [],
            static fn (array $group): bool => count($group) <= $maxSynonymGroupSize
                && ($blocked === [] || array_intersect(self::phrases($group), $blocked) === [])
        ));
        self::$cache = []; // only the live bundle's entry is worth keeping

        $version = substr(sha1($key), 0, 12);

        $aliases = self::mergeOverlapping(self::readGroups($aliasesPath) ?? []);
        $tokens = [];
        foreach ($aliases as $group) {
            foreach ($group as $term) {
                foreach (Tokenizer::split($term) as $token) {
                    $tokens[$token] = true;
                }
            }
        }

        return self::$cache[$key] = new self(
            array_merge($synonyms, $aliases),
            $version,
            array_map('strval', array_keys($tokens))
        );
    }

    /**
     * @return list<string> the blocklisted phrases, normalized, sorted and de-duplicated
     */
    public static function blockedPhrases(string $blocklist): array
    {
        $phrases = array_unique(self::phrases(explode(',', $blocklist)));
        sort($phrases);

        return $phrases;
    }

    /**
     * @param list<string> $terms
     * @return list<string> the terms as normalized token phrases, empty ones left out
     */
    private static function phrases(array $terms): array
    {
        $phrases = [];
        foreach ($terms as $term) {
            $phrase = implode(' ', Tokenizer::split(Normalizer::normalize((string) $term)));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * Owner groups that share a term are one group. Expansion swaps a term for the
     * other terms of its own group only, so "ps5" listed in two groups would leave
     * "پی اس فایو" (group A) unable to reach "پلی استیشن ۵" (group B).
     *
     * @param list<list<string>> $groups
     * @return list<list<string>>
     */
    public static function mergeOverlapping(array $groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            $terms = [];
            foreach ($group as $term) {
                $tokens = Tokenizer::split(Normalizer::normalize($term));
                if ($tokens !== []) {
                    $terms[] = implode(' ', $tokens);
                }
            }
            $terms = array_values(array_unique($terms));
            $into = null;
            foreach ($merged as $i => $existing) {
                if (array_intersect($existing, $terms) === []) {
                    continue;
                }
                if ($into === null) {
                    $into = $i;
                    $merged[$i] = array_values(array_unique(array_merge($existing, $terms)));
                } else { // the group bridges two earlier ones
                    $merged[$into] = array_values(array_unique(array_merge($merged[$into], $existing)));
                    unset($merged[$i]);
                }
            }
            if ($into === null) {
                $merged[] = $terms;
            }
        }

        return array_values($merged);
    }

    /** Short hash of the alias and synonym files this instance was built from ('' when built from groups). */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * The alias / synonym term that reads like $normalized once spaces are ignored
     * ("رزیدنتاویل" for the term "رزیدنت اویل"), or null. A suggestion the owner
     * wrote beats one guessed by edit distance.
     */
    public function spacingVariant(string $normalized): ?string
    {
        $phrase = $this->byCollapsed[Normalizer::collapse($normalized)] ?? null;

        return $phrase !== null && $phrase !== $normalized ? $phrase : null;
    }

    /** Tokens of the merged owner alias groups: the words the spell checker must treat as known. @return list<string> */
    public function aliasTokens(): array
    {
        return $this->aliasTokens;
    }

    /** True when $token is a word of one of the owner's alias terms. */
    public function hasAliasToken(string $token): bool
    {
        return isset($this->aliasTokenSet[$token]);
    }

    /**
     * The alias / synonym terms (as phrases) that are in the same group as the term $phrase, or
     * none. The term itself is not included.
     *
     * @return list<string>
     */
    public function alternativesOf(string $phrase): array
    {
        return array_map(
            static fn (array $tokens): string => implode(' ', $tokens),
            $this->alternatives[$phrase] ?? []
        );
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
                $spelling = $this->otherSpelling($tokens, $start) ?? $this->spacedTerm($tokens, $start);
                if ($spelling === null) {
                    $start++;
                    continue;
                }
                [$length, $alternatives] = $spelling;
                $spans[] = [$start, $length, $alternatives];
                $start += $length;
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
     * The alias group a letter-digit name belongs to under its other spelling: the token "gta6" when
     * the groups list "gta 6", or the tokens "gta" "6" when they list "gta6". The span's replacements
     * are that spelling and the group's other terms.
     *
     * @param list<string> $tokens
     * @return array{0: int, 1: list<list<string>>}|null [span length, replacements]
     */
    private function otherSpelling(array $tokens, int $start): ?array
    {
        if (preg_match(self::LETTER_DIGIT, $tokens[$start], $m) === 1) {
            $other = $m[1] . ' ' . $m[2];
            $length = 1;
        } elseif (
            isset($tokens[$start + 1])
            && preg_match('/^[a-z]{2,5}$/', $tokens[$start]) === 1
            && preg_match('/^\d{1,4}$/', $tokens[$start + 1]) === 1
        ) {
            $other = $tokens[$start] . $tokens[$start + 1];
            $length = 2;
        } else {
            return null;
        }
        if (!isset($this->alternatives[$other])) {
            return null;
        }

        return [$length, array_merge([explode(' ', $other)], $this->alternatives[$other])];
    }

    /**
     * A run of two or more query words that reads like an alias term once the spaces are ignored
     * ("play station 4" for the term "playstation 4"), with the term and its group as replacements.
     *
     * @param list<string> $tokens
     * @return array{0: int, 1: list<list<string>>}|null [span length, replacements]
     */
    private function spacedTerm(array $tokens, int $start): ?array
    {
        $count = count($tokens);
        for ($length = min($this->longestPhrase + 2, $count - $start); $length >= 2; $length--) {
            $span = array_slice($tokens, $start, $length);
            $phrase = $this->byCollapsed[Normalizer::collapse(implode('', $span))] ?? null;
            if ($phrase !== null && $phrase !== implode(' ', $span) && isset($this->alternatives[$phrase])) {
                return [$length, array_merge([explode(' ', $phrase)], $this->alternatives[$phrase])];
            }
        }

        return null;
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
}
