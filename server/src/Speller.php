<?php

declare(strict_types=1);

namespace App;

use APCUIterator;
use PDO;

/**
 * "Did you mean" spelling correction over a token vocabulary derived from the
 * catalog. Works for Persian and Latin (multibyte-aware edit distance).
 *
 * The vocabulary comes from the bundle's `spellcheck.txt` (built offline by
 * pipeline/keyword.py) and is cached like the vectors: parsed once per PHP
 * worker, and in APCu when available so a fresh worker skips the parse. Only
 * when the dictionary is missing does the caller fall back to scanning the
 * products table ({@see fromProducts}), which is too slow for the latency budget
 * on a full catalog.
 *
 * Only terms seen in at least `minFrequency` products are offered as
 * corrections: a rare word (a one-off spelling, a typo in the catalog itself) is
 * a poor suggestion even at a small edit distance. Any known term, rare or not,
 * is still left uncorrected in the query.
 *
 * Lookup cost: every term is pre-encoded as one byte per character (see
 * {@see index}), so the edit distance runs in PHP's native `levenshtein()`
 * instead of a PHP-level loop, and terms are bucketed by length so only lengths
 * within the threshold are scanned. The result is identical to a multibyte
 * Levenshtein; terms that cannot be encoded use the PHP-level distance.
 */
final class Speller
{
    public const DICTIONARY_FILE = 'spellcheck.txt';

    private const APCU_PREFIX = 'search.spell.';
    // Byte 255 is reserved for query characters absent from the vocabulary's
    // alphabet: no term contains it, so it always counts as a mismatch.
    private const MAX_CODES = 255;
    private const UNKNOWN_CODE = "\xFF";
    private const SHORT_TOKEN = 4;

    /**
     * Parsed dictionaries for this process, keyed by {@see cacheKey}.
     *
     * Each entry holds the vocabulary and its {@see index}.
     *
     * @var array<string, array{vocabulary: array<string, int>, index: array<string, mixed>}>
     */
    private static array $processCache = [];

    /**
     * Spellers over those dictionaries, keyed by cache key and settings.
     *
     * @var array<string, self>
     */
    private static array $instances = [];

    /** @var array<string, int> token => frequency */
    private array $vocabulary;
    private int $maxDistance;
    private int $minFrequency;
    /** @var array<string, string> character => single-byte code */
    private array $codes;
    /**
     * Terms grouped by character length, in vocabulary order.
     *
     * @var array<int, list<array{0: string, 1: ?string, 2: int}>> [term, encoded|null, frequency]
     */
    private array $byLength;

    /**
     * @param array<string, int> $vocabulary
     * @param null|array{codes: array<string, string>, byLength: array<int, list<array{0: string, 1: ?string, 2: int}>>}
     *        $index A prebuilt {@see index} of $vocabulary (from the cache).
     */
    public function __construct(
        array $vocabulary,
        int $maxDistance = 2,
        ?array $index = null,
        int $minFrequency = 1
    ) {
        $this->vocabulary = $vocabulary;
        $this->maxDistance = max(1, $maxDistance);
        $this->minFrequency = max(1, $minFrequency);
        $index ??= self::index($vocabulary);
        $this->codes = $index['codes'];
        $this->byLength = $index['byLength'];
    }

    /**
     * Build a token => frequency map from already-normalized texts.
     *
     * @param iterable<string> $normalizedTexts
     * @return array<string, int>
     */
    public static function buildVocabulary(iterable $normalizedTexts, int $minLength = 2): array
    {
        $vocabulary = [];
        foreach ($normalizedTexts as $text) {
            foreach (Tokenizer::split($text) as $token) {
                if (mb_strlen($token) >= $minLength) {
                    $vocabulary[$token] = ($vocabulary[$token] ?? 0) + 1;
                }
            }
        }

        return $vocabulary;
    }

    /**
     * Parse the bundle dictionary: one "token<TAB>count" line per term (the
     * format written by pipeline/keyword.py). Malformed lines are skipped.
     *
     * @return array<string, int>
     */
    public static function parseDictionary(string $contents): array
    {
        $vocabulary = [];
        foreach (explode("\n", $contents) as $line) {
            $parts = explode("\t", rtrim($line, "\r"));
            if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[1])) {
                continue;
            }
            $vocabulary[$parts[0]] = (int) $parts[1];
        }

        return $vocabulary;
    }

    /**
     * The speller for a bundle dictionary, or null when the file is absent (the
     * caller then falls back to {@see fromProducts}). Cached per worker and in
     * APCu, keyed by the file's identity, so the reload swap — which puts a new
     * file in place — is picked up without any explicit signal.
     */
    public static function fromDictionary(
        string $path,
        ?bool $useApcu = null,
        int $maxDistance = 2,
        int $minFrequency = 1
    ): ?self {
        $key = self::cacheKey($path);
        if ($key === null) {
            return null;
        }
        $instanceKey = $key . '|' . $maxDistance . '|' . $minFrequency;
        if (isset(self::$instances[$instanceKey])) {
            return self::$instances[$instanceKey];
        }
        $parsed = self::loadDictionary($path, $key, $useApcu);
        if ($parsed === null) {
            return null;
        }

        return self::$instances[$instanceKey] =
            new self($parsed['vocabulary'], $maxDistance, $parsed['index'], $minFrequency);
    }

    /**
     * @return null|array{vocabulary: array<string, int>, index: array<string, mixed>}
     */
    private static function loadDictionary(string $path, string $key, ?bool $useApcu): ?array
    {
        if (isset(self::$processCache[$key])) {
            return self::$processCache[$key];
        }

        $apcu = $useApcu ?? self::apcuAvailable();
        $apcuKey = self::APCU_PREFIX . $key;
        if ($apcu) {
            $hit = apcu_fetch($apcuKey, $ok);
            if ($ok === true && is_array($hit) && isset($hit['vocabulary'], $hit['index'])) {
                return self::$processCache[$key] = $hit;
            }
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $vocabulary = self::parseDictionary($contents);
        $parsed = ['vocabulary' => $vocabulary, 'index' => self::index($vocabulary)];
        if ($apcu) {
            // Best-effort: a dictionary larger than the APCu segment simply is not
            // shared; this worker still caches it below.
            @apcu_store($apcuKey, $parsed);
        }

        return self::$processCache[$key] = $parsed;
    }

    /**
     * Called by POST /reload after the swap: drop every cached dictionary (the
     * previous bundle's entry would otherwise linger in APCu) and load the new
     * one, so the first search after a reload is already warm.
     */
    public static function reloadDictionary(
        string $path,
        ?bool $useApcu = null,
        int $maxDistance = 2,
        int $minFrequency = 1
    ): ?self {
        self::$processCache = [];
        self::$instances = [];
        if ($useApcu ?? self::apcuAvailable()) {
            @apcu_delete(new APCUIterator('/^' . preg_quote(self::APCU_PREFIX, '/') . '/'));
        }

        return self::fromDictionary($path, $useApcu, $maxDistance, $minFrequency);
    }

    /**
     * Fallback when the bundle has no dictionary: build the vocabulary by
     * scanning the products table. Not cached — it is only a stopgap until a
     * bundle is loaded, and a per-worker copy would go stale on reload. Uses the
     * same high-signal fields as the pipeline dictionary (not descriptions), one
     * count per product.
     */
    public static function fromProducts(
        PDO $pdo,
        string $productsTable,
        int $maxDistance = 2,
        int $minFrequency = 1
    ): self {
        $texts = [];
        $sql = 'SELECT normalized_title, brand, category, model FROM ' . Identifier::quote($productsTable);
        foreach ($pdo->query($sql) as $row) {
            $texts[] = implode(' ', array_unique(Tokenizer::split(implode(' ', [
                $row['normalized_title'],
                Normalizer::normalize((string) $row['brand']),
                Normalizer::normalize((string) $row['category']),
                Normalizer::normalize((string) $row['model']),
            ]))));
        }

        return new self(self::buildVocabulary($texts), $maxDistance, null, $minFrequency);
    }

    /**
     * Return a corrected form of the normalized query, or null if nothing is
     * improved. Known tokens and pure digits are left untouched.
     */
    public function suggest(string $normalizedQuery): ?string
    {
        $tokens = Tokenizer::split($normalizedQuery);
        if ($tokens === []) {
            return null;
        }

        $changed = false;
        $corrected = [];
        foreach ($tokens as $token) {
            if (isset($this->vocabulary[$token]) || ctype_digit($token)) {
                $corrected[] = $token;
                continue;
            }
            $candidate = $this->bestCandidate($token);
            if ($candidate !== null && $candidate !== $token) {
                $corrected[] = $candidate;
                $changed = true;
            } else {
                $corrected[] = $token;
            }
        }

        if (!$changed) {
            return null;
        }
        $suggestion = implode(' ', $corrected);

        return $suggestion === $normalizedQuery ? null : $suggestion;
    }

    private function bestCandidate(string $token): ?string
    {
        $chars = mb_str_split($token);
        $length = count($chars);
        // Be stricter on short tokens: two edits on four letters is a different word.
        $threshold = $length <= self::SHORT_TOKEN ? 1 : $this->maxDistance;

        $encoded = '';
        foreach ($chars as $char) {
            $encoded .= $this->codes[$char] ?? self::UNKNOWN_CODE;
        }

        $bestTerm = null;
        $bestDistance = PHP_INT_MAX;
        $bestFrequency = -1;

        for ($termLength = max(1, $length - $threshold); $termLength <= $length + $threshold; $termLength++) {
            foreach ($this->byLength[$termLength] ?? [] as [$term, $termEncoded, $frequency]) {
                $distance = $termEncoded !== null
                    ? levenshtein($encoded, $termEncoded)
                    : self::distance($token, $term);
                if ($distance > $threshold || $frequency < $this->minFrequency) {
                    continue;
                }

                $better = $distance < $bestDistance
                    || ($distance === $bestDistance && $frequency > $bestFrequency)
                    || ($distance === $bestDistance && $frequency === $bestFrequency
                        && ($bestTerm === null || $term < $bestTerm));

                if ($better) {
                    $bestTerm = $term;
                    $bestDistance = $distance;
                    $bestFrequency = $frequency;
                }
            }
        }

        return $bestTerm;
    }

    /**
     * Assign the most frequent characters a single-byte code each and bucket the
     * encoded terms by length. A term with a character beyond the first
     * {@see MAX_CODES} keeps a null encoding and uses {@see distance}.
     *
     * @param array<string, int> $vocabulary
     * @return array{codes: array<string, string>, byLength: array<int, list<array{0: string, 1: ?string, 2: int}>>}
     */
    private static function index(array $vocabulary): array
    {
        $split = [];
        $charCounts = [];
        foreach ($vocabulary as $term => $frequency) {
            $term = (string) $term; // numeric-string keys arrive as int
            $chars = mb_str_split($term);
            $split[$term] = $chars;
            foreach ($chars as $char) {
                $charCounts[$char] = ($charCounts[$char] ?? 0) + 1;
            }
        }
        arsort($charCounts);

        $codes = [];
        foreach (array_keys($charCounts) as $i => $char) {
            if ($i >= self::MAX_CODES) {
                break;
            }
            $codes[(string) $char] = chr($i);
        }

        $byLength = [];
        foreach ($split as $term => $chars) {
            $encoded = '';
            foreach ($chars as $char) {
                if (!isset($codes[$char])) {
                    $encoded = null;
                    break;
                }
                $encoded .= $codes[$char];
            }
            $term = (string) $term;
            $byLength[count($chars)][] = [$term, $encoded, $vocabulary[$term]];
        }

        return ['codes' => $codes, 'byLength' => $byLength];
    }

    /** Multibyte Levenshtein distance (PHP's levenshtein is byte-based). */
    private static function distance(string $a, string $b): int
    {
        $ca = mb_str_split($a);
        $cb = mb_str_split($b);
        $la = count($ca);
        $lb = count($cb);
        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }

        $previous = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $ca[$i - 1] === $cb[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }
            $previous = $current;
        }

        return $previous[$lb];
    }

    /**
     * Identity of the dictionary file: a swapped-in bundle is a different file
     * (new inode, and usually new size/mtime), so a stale parse is never reused.
     * Null when the file is missing.
     */
    private static function cacheKey(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || !is_file($path)) {
            return null;
        }

        return md5($path . '|' . $stat['ino'] . '|' . $stat['size'] . '|' . $stat['mtime']);
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
