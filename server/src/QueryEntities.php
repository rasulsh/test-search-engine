<?php

declare(strict_types=1);

namespace App;

/**
 * Brand and platform recognition for a query (M44).
 *
 * The bundle's entities.json holds the catalog's brands (normalized product `brand` values) and a short
 * seed list of gaming platforms ("ps4": its spellings). At query time a query phrase is a brand when it is
 * one, or when the owner's alias group it belongs to holds one ("شیامی" = "شیائومی" = "xiaomi"); a platform
 * likewise through the aliases of the seed spellings. Matching is by whole token phrases, longest first, over
 * the query and its alias variants.
 *
 * {@see statuses()} then tells, per candidate product, whether it carries the recognized brand / platform
 * (+1), another one (-1), or none (0). A dimension is active only when at least one candidate carries the
 * recognized entity: with no product of the brand among the candidates there is nothing to prefer, and the
 * other brands stay as they are (the caller reports the brand as asked for but unmatched). The Ranker turns
 * the statuses into a boost, a demotion and the loss of the floor exemption; {@see reorder()} is the
 * keyword-only equivalent.
 *
 * A used / new condition is not recognized: nothing in the catalog export reliably marks it (needs the owner).
 */
final class QueryEntities
{
    public const FILE = 'entities.json';
    public const VERSION = 1;

    /** @var array<string, array{brands: list<string>, platforms: array<string, list<string>>}> by file identity */
    private static array $cache = [];

    /** @var array<string, true> brand phrase => true */
    private array $brands = [];
    /** @var array<string, string> platform spelling (phrase) => platform key, aliases included */
    private array $platformTerms = [];
    /** @var array<string, list<string>> platform key => its spellings, aliases included */
    private array $platformPhrases = [];
    private int $longest = 1;
    private ?Synonyms $synonyms;

    /**
     * @param list<string> $brands normalized brand names
     * @param array<string, list<string>> $platforms platform key => spellings
     */
    public function __construct(array $brands, array $platforms, ?Synonyms $synonyms = null)
    {
        foreach ($brands as $brand) {
            $phrase = implode(' ', Tokenizer::split(Normalizer::normalize($brand)));
            if (mb_strlen($phrase) >= 2 && !ctype_digit($phrase)) {
                $this->brands[$phrase] = true;
                $this->longest = max($this->longest, count(explode(' ', $phrase)));
            }
        }
        foreach ($platforms as $key => $spellings) {
            foreach ($spellings as $spelling) {
                $phrase = implode(' ', Tokenizer::split(Normalizer::normalize($spelling)));
                if ($phrase === '') {
                    continue;
                }
                foreach (array_merge([$phrase], $synonyms?->alternativesOf($phrase) ?? []) as $term) {
                    $this->platformTerms[$term] ??= (string) $key;
                    $this->platformPhrases[(string) $key][$term] = $term;
                    $this->longest = max($this->longest, count(explode(' ', $term)));
                }
            }
        }
        $this->platformPhrases = array_map('array_values', $this->platformPhrases);
        $this->synonyms = $synonyms;
    }

    /**
     * The entities of the bundle in $dataDir; a missing or unreadable file means none (/reload validates a
     * present one). The parsed file is cached per worker by its identity; the alias group lookups use
     * $synonyms, which changes with the owner's alias file.
     */
    public static function fromDirectory(string $dataDir, ?Synonyms $synonyms = null): self
    {
        $path = $dataDir . '/' . self::FILE;
        clearstatcache(true, $path);
        $stat = @stat($path);
        $key = $stat === false ? '-' : $path . ':' . $stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'];
        if (!isset(self::$cache[$key])) {
            $json = is_file($path) ? @file_get_contents($path) : false;
            self::$cache = []; // only the live bundle's entry is worth keeping
            self::$cache[$key] = ($json === false ? null : self::parse($json)) ?? ['brands' => [], 'platforms' => []];
        }

        return new self(self::$cache[$key]['brands'], self::$cache[$key]['platforms'], $synonyms);
    }

    /** Drop the cached file (POST /reload, after the swap). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Parse the file: {"version": 1, "brands": [{"name": "xiaomi", "count": 14}, ...],
     * "platforms": {"ps4": ["ps4", "playstation 4"], ...}}. Null when the JSON or its shape is invalid.
     *
     * @return array{brands: list<string>, platforms: array<string, list<string>>}|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (
            !is_array($data) || ($data['version'] ?? null) !== self::VERSION
            || !is_array($data['brands'] ?? null) || !array_is_list($data['brands'])
            || !is_array($data['platforms'] ?? null)
        ) {
            return null;
        }
        $brands = [];
        foreach ($data['brands'] as $brand) {
            if (!is_array($brand) || !is_string($brand['name'] ?? null) || !is_int($brand['count'] ?? null)) {
                return null;
            }
            $brands[] = $brand['name'];
        }
        $platforms = [];
        foreach ($data['platforms'] as $key => $spellings) {
            if (!is_string($key) || !is_array($spellings) || !array_is_list($spellings)) {
                return null;
            }
            foreach ($spellings as $spelling) {
                if (!is_string($spelling)) {
                    return null;
                }
            }
            $platforms[$key] = $spellings;
        }

        return ['brands' => $brands, 'platforms' => $platforms];
    }

    public function isEmpty(): bool
    {
        return $this->brands === [] && $this->platformTerms === [];
    }

    /**
     * The brands and platforms a query names, from its tokens and the alias variants of them.
     *
     * @param list<list<string>> $variants the query's token lists, the literal one first
     * @return array{brands: list<string>, platforms: list<string>}
     */
    public function recognize(array $variants): array
    {
        $brands = [];
        $platforms = [];
        foreach ($variants as $tokens) {
            $count = count($tokens);
            for ($start = 0; $start < $count;) {
                $matched = 0;
                for ($length = min($this->longest, $count - $start); $length > 0 && $matched === 0; $length--) {
                    $phrase = implode(' ', array_slice($tokens, $start, $length));
                    $found = false;
                    foreach (array_merge([$phrase], $this->synonyms?->alternativesOf($phrase) ?? []) as $term) {
                        if (isset($this->brands[$term])) {
                            $brands[$term] = true;
                            $found = true;
                        }
                    }
                    if (isset($this->platformTerms[$phrase])) {
                        $platforms[$this->platformTerms[$phrase]] = true;
                        $found = true;
                    }
                    $matched = $found ? $length : 0;
                }
                $start += max(1, $matched);
            }
        }

        return ['brands' => array_map('strval', array_keys($brands)), 'platforms' => array_map('strval', array_keys($platforms))];
    }

    /**
     * Per candidate: +1 when it carries a recognized brand / platform, -1 when it carries another one
     * and none of the recognized, 0 when it names none. A dimension is `active` when some candidate is +1.
     *
     * @param array{brands: list<string>, platforms: list<string>} $recognized
     * @param array<int, array<string, mixed>> $signals product_id => normalized `brand`, `title`, `tags`, `category`
     * @return array{active: array{brand: bool, platform: bool}, status: array<int, array{brand: int, platform: int}>}
     */
    public function statuses(array $recognized, array $signals): array
    {
        $brands = array_flip($recognized['brands']);
        $platforms = array_flip($recognized['platforms']);
        $active = ['brand' => false, 'platform' => false];
        $status = [];
        foreach ($signals as $id => $signal) {
            $brand = 0;
            if ($brands !== []) {
                $name = implode(' ', Tokenizer::split((string) ($signal['brand'] ?? '')));
                $brand = $name === '' ? 0 : (isset($brands[$name]) ? 1 : -1);
            }
            $platform = 0;
            if ($platforms !== []) {
                $own = $this->platformsOf($signal);
                $platform = $own === [] ? 0 : (array_intersect_key($own, $platforms) !== [] ? 1 : -1);
            }
            $active['brand'] = $active['brand'] || $brand === 1;
            $active['platform'] = $active['platform'] || $platform === 1;
            $status[(int) $id] = ['brand' => $brand, 'platform' => $platform];
        }

        return ['active' => $active, 'status' => $status];
    }

    /**
     * Keyword-only order: candidates carrying the recognized entities first, the neutral ones next, the ones
     * carrying another brand / platform last; each group keeps its order. Nothing is dropped.
     *
     * @param list<int> $ids
     * @param array{active: array{brand: bool, platform: bool}, status: array<int, array{brand: int, platform: int}>} $entity
     * @return list<int>
     */
    public static function reorder(array $ids, array $entity): array
    {
        $rank = static function (int $id) use ($entity): int {
            $positive = 0;
            $negative = 0;
            foreach (['brand', 'platform'] as $dimension) {
                $value = $entity['active'][$dimension] ? ($entity['status'][$id][$dimension] ?? 0) : 0;
                $positive += $value > 0 ? 1 : 0;
                $negative += $value < 0 ? 1 : 0;
            }

            return $negative > 0 ? 1 : ($positive > 0 ? -1 : 0);
        };
        $order = array_flip($ids);
        usort($ids, static fn (int $a, int $b): int => [$rank($a), $order[$a]] <=> [$rank($b), $order[$b]]);

        return $ids;
    }

    /**
     * @param array<string, mixed> $signal
     * @return array<string, true> the platform keys the product's title, tags or category name
     */
    private function platformsOf(array $signal): array
    {
        $haystack = ' ' . implode(' ', Tokenizer::split(
            ($signal['title'] ?? '') . ' ' . ($signal['tags'] ?? '') . ' ' . ($signal['category'] ?? '')
        )) . ' ';
        $own = [];
        foreach ($this->platformPhrases as $key => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, ' ' . $phrase . ' ')) {
                    $own[$key] = true;
                    break;
                }
            }
        }

        return $own;
    }
}
