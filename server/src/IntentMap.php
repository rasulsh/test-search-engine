<?php

declare(strict_types=1);

namespace App;

/**
 * Owner-curated map from an intent word or phrase to category and / or tag
 * names (M34), shipped in the bundle as intent_map.json:
 *
 *   {"فرمون": {"category": ["لوازم جانبی گیمینگ"], "tags": ["فرمان بازی"]}}
 *
 * For a word the shopper types that is in none of the product's own fields
 * ("steering wheel" for a racing-wheel accessory), {@see resolve()} returns the
 * mapped names; the Ranker then treats them as if the query had named that
 * category / tag, so its existing match boosts apply. No ranking math lives
 * here, and a boost only reorders candidates the keyword / semantic tiers
 * already returned.
 *
 * Keys are matched as whole token sequences, never substrings. Everything is
 * normalized on load (idempotent on the pipeline's output) so an entry edited by
 * hand on the server still matches the normalized query.
 */
final class IntentMap
{
    public const FILE = 'intent_map.json';

    /** @var array<string, self> keyed by the file's identity */
    private static array $cache = [];

    /** @var array<string, array{categories: list<string>, tags: list<string>}> phrase => names */
    private array $entries = [];
    private int $longestPhrase = 0;

    /** @param array<string, array{category?: list<string>, tags?: list<string>}> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $phrase => $entry) {
            $tokens = Tokenizer::split(Normalizer::normalize((string) $phrase));
            $categories = self::names($entry['category'] ?? []);
            $tags = self::names($entry['tags'] ?? []);
            if ($tokens === [] || ($categories === [] && $tags === [])) {
                continue;
            }
            $this->entries[implode(' ', $tokens)] = ['categories' => $categories, 'tags' => $tags];
            $this->longestPhrase = max($this->longestPhrase, count($tokens));
        }
    }

    /**
     * The map of the bundle in $dataDir; a missing or unreadable file is an
     * empty map (search must never fail on it: /reload validates the file before
     * it goes live). Cached per worker, keyed by the file's identity, so the
     * reload swap is picked up unaided.
     */
    public static function fromDirectory(string $dataDir): self
    {
        $path = $dataDir . '/' . self::FILE;
        $key = self::identity($path);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $json = is_file($path) ? @file_get_contents($path) : false;
        $entries = $json === false ? null : self::parse($json);
        self::$cache = []; // only the live bundle's entry is worth keeping

        return self::$cache[$key] = new self($entries ?? []);
    }

    /** Drop the cached map (POST /reload, after the swap). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Parse the file: a JSON object from term to {category?: [..], tags?: [..]}
     * with at least one of the two. Null when the JSON or its shape is invalid.
     *
     * @return array<string, array{category?: list<string>, tags?: list<string>}>|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }
        foreach ($data as $entry) {
            if (!is_array($entry) || $entry === [] || array_diff(array_keys($entry), ['category', 'tags']) !== []) {
                return null;
            }
            foreach ($entry as $names) {
                if (!is_array($names) || !array_is_list($names)) {
                    return null;
                }
                foreach ($names as $name) {
                    if (!is_string($name)) {
                        return null;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * The category and tag names mapped from every intent key found whole in the
     * query, left to right and longest first, from at most $maxTerms keys.
     *
     * @return array{categories: list<string>, tags: list<string>}
     */
    public function resolve(string $normalizedQuery, int $maxTerms): array
    {
        $resolved = ['categories' => [], 'tags' => []];
        if ($this->entries === [] || $maxTerms < 1) {
            return $resolved;
        }
        $tokens = Tokenizer::split($normalizedQuery);
        $count = count($tokens);
        $found = 0;
        for ($start = 0; $start < $count && $found < $maxTerms;) {
            for ($length = min($this->longestPhrase, $count - $start); $length > 0; $length--) {
                $phrase = implode(' ', array_slice($tokens, $start, $length));
                if (isset($this->entries[$phrase])) {
                    break;
                }
            }
            if ($length === 0) {
                $start++;
                continue;
            }
            $found++;
            foreach (['categories', 'tags'] as $kind) {
                $merged = array_merge($resolved[$kind], $this->entries[$phrase][$kind]);
                $resolved[$kind] = array_values(array_unique($merged));
            }
            $start += $length;
        }

        return $resolved;
    }

    /**
     * @param list<string> $names
     * @return list<string> normalized, non-empty, unique
     */
    private static function names(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $tokens = Tokenizer::split(Normalizer::normalize($name));
            if ($tokens !== []) {
                $result[implode(' ', $tokens)] = true;
            }
        }

        return array_map('strval', array_keys($result));
    }

    private static function identity(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? '-' : $path . ':' . $stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'];
    }
}
