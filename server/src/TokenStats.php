<?php

declare(strict_types=1);

namespace App;

/**
 * Token weighting for the keyword tier (M44): how much a query token says about a product.
 *
 * The bundle's token_df.json holds, per token, the number of products whose normalized title or
 * tags contain it (pipeline/keyword.py build_token_df). A token's weight is its inverse document
 * frequency, log(N / (1 + df)) scaled to [0, 1] by log(N). A token held by more than
 * `generic_df_ratio` of the catalog, or listed in `search.stopwords`, is GENERIC ("بازی", "خرید",
 * "یک"): it can never make a product a keyword match by itself and only adds a small score.
 * A bundle without the file has no frequencies: only the stopwords are generic and every
 * other token weighs 1.
 */
final class TokenStats
{
    public const FILE = 'token_df.json';
    public const VERSION = 1;
    /** Score weight of a generic token (a non-generic one weighs at least MIN_WEIGHT). */
    public const GENERIC_WEIGHT = 0.1;
    private const MIN_WEIGHT = 0.2;

    /** @var array<string, self> keyed by the file's identity and the settings */
    private static array $cache = [];

    private int $total;
    /** @var array<string, int> */
    private array $frequencies;
    private float $genericRatio;
    /** @var array<string, true> */
    private array $stopwords = [];

    /**
     * @param array<string, int> $frequencies token => number of products
     * @param list<string> $stopwords raw words; normalized here
     */
    public function __construct(
        int $total = 0,
        array $frequencies = [],
        float $genericRatio = 0.15,
        array $stopwords = []
    ) {
        $this->total = max(0, $total);
        $this->frequencies = $frequencies;
        $this->genericRatio = $genericRatio;
        foreach ($stopwords as $word) {
            foreach (Tokenizer::split(Normalizer::normalize($word)) as $token) {
                $this->stopwords[$token] = true;
            }
        }
    }

    /**
     * The statistics of the bundle in $dataDir; a missing or unreadable file means no frequencies
     * (/reload validates a present one). Cached per worker, keyed by the file's identity, so the
     * reload swap is picked up unaided.
     *
     * @param string $stopwordsCsv `search.stopwords`
     */
    public static function fromDirectory(string $dataDir, float $genericRatio, string $stopwordsCsv): self
    {
        $path = $dataDir . '/' . self::FILE;
        clearstatcache(true, $path);
        $stat = @stat($path);
        $key = ($stat === false ? '-' : $path . ':' . $stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'])
            . '|' . $genericRatio . '|' . $stopwordsCsv;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $json = is_file($path) ? @file_get_contents($path) : false;
        $parsed = $json === false ? null : self::parse($json);
        self::$cache = []; // only the live bundle's entry is worth keeping

        return self::$cache[$key] = new self(
            $parsed['n'] ?? 0,
            $parsed['df'] ?? [],
            $genericRatio,
            explode(',', $stopwordsCsv)
        );
    }

    /** Drop the cached statistics (POST /reload, after the swap). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Parse the file: {"version": 1, "n": <products>, "df": {"token": <products>, ...}}. Null when
     * the JSON, its version or its shape is invalid.
     *
     * @return array{n: int, df: array<string, int>}|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (
            !is_array($data) || ($data['version'] ?? null) !== self::VERSION
            || !is_int($data['n'] ?? null) || $data['n'] < 0 || !is_array($data['df'] ?? null)
        ) {
            return null;
        }
        foreach ($data['df'] as $token => $count) {
            if (!is_int($count) || $count < 0 || $token === '') {
                return null;
            }
        }

        return ['n' => $data['n'], 'df' => array_map('intval', $data['df'])];
    }

    public function isGeneric(string $token): bool
    {
        if (isset($this->stopwords[$token])) {
            return true;
        }

        return $this->total > 0 && $this->genericRatio > 0.0
            && ($this->frequencies[$token] ?? 0) / $this->total > $this->genericRatio;
    }

    /** The token's score weight in (0, 1]: generic tokens weigh little, rare ones most. */
    public function weight(string $token): float
    {
        if ($this->isGeneric($token)) {
            return self::GENERIC_WEIGHT;
        }
        if ($this->total < 3) {
            return 1.0;
        }
        $idf = log($this->total / (1 + ($this->frequencies[$token] ?? 0))) / log($this->total);

        return min(1.0, max(self::MIN_WEIGHT, $idf));
    }
}
