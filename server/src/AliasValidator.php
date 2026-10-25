<?php

declare(strict_types=1);

namespace App;

/**
 * The alias manager's save checks (M41). Errors block a save; warnings need an explicit confirm and
 * are raised only for terms of groups the editor changed, so an old group never nags on every save.
 * Both come back as codes with context: the wording lives in AdminStrings.
 *
 * Errors: shape (not a list of lists of strings), empty_term (also a term that normalizes to
 * nothing), too_few_terms (fewer than 2 distinct normalized terms), duplicate_term, term_too_long.
 * Warnings: in_other_group (the merged group is shown), short_term, many_matches, blocklisted.
 */
final class AliasValidator
{
    /** Counting runs one keyword query per term; a huge raw paste must not turn into hundreds of them. */
    private const MAX_COUNTED_TERMS = 50;

    private const MIN_TERM_CHARS = 3;

    /** @var callable(string): int|null */
    private $counter;

    /**
     * @param list<string> $blocklist normalized words the did-you-mean never offers (search.suggest_blocklist)
     * @param callable(string): int|null $counter products a term matches, capped just above $warnMatches
     */
    public function __construct(
        private readonly int $maxTermChars,
        private readonly int $warnMatches,
        private readonly array $blocklist = [],
        ?callable $counter = null
    ) {
        $this->counter = $counter;
    }

    /** @param array<string, mixed> $config the server config array */
    public static function fromConfig(array $config, ?callable $counter = null): self
    {
        $blocklist = array_values(array_filter(array_map(
            static fn (string $word): string => Normalizer::normalize($word),
            explode(',', (string) $config['search']['suggest_blocklist'])
        ), static fn (string $word): bool => $word !== ''));

        return new self(
            (int) $config['admin']['alias_max_term_chars'],
            (int) $config['admin']['alias_warn_matches'],
            $blocklist,
            $counter
        );
    }

    /** The normalized phrase of a term: what the search compares ('' when nothing is left). */
    public static function phrase(string $term): string
    {
        return implode(' ', Tokenizer::split(Normalizer::normalize($term)));
    }

    /**
     * Decodes the advanced editor's text. Anything but a JSON list of lists of strings is a `shape` error.
     *
     * @return array{groups: ?list<mixed>, errors: list<array<string, mixed>>}
     */
    public static function decode(string $json): array
    {
        $groups = json_decode($json, true);
        if (!is_array($groups) || !array_is_list($groups)) {
            return ['groups' => null, 'errors' => [['code' => 'shape']]];
        }

        return ['groups' => $groups, 'errors' => []];
    }

    /**
     * @param mixed $groups the proposed file content
     * @param list<list<string>> $current the stored groups, to find which groups changed
     * @return array{
     *     groups: list<list<string>>,
     *     errors: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>
     * } `groups` is the cleaned proposal (terms trimmed, as typed); empty when `errors` is not
     */
    public function validate(mixed $groups, array $current): array
    {
        $shape = $this->shape($groups);
        if ($shape === null) {
            return ['groups' => [], 'errors' => [['code' => 'shape']], 'warnings' => []];
        }

        $errors = $this->errors($shape);
        if ($errors !== []) {
            return ['groups' => [], 'errors' => $errors, 'warnings' => []];
        }

        return ['groups' => $shape, 'errors' => [], 'warnings' => $this->warnings($shape, $current)];
    }

    /** @return list<list<string>>|null */
    private function shape(mixed $groups): ?array
    {
        if (!is_array($groups) || !array_is_list($groups)) {
            return null;
        }
        $clean = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !array_is_list($group)) {
                return null;
            }
            $terms = [];
            foreach ($group as $term) {
                if (!is_string($term)) {
                    return null;
                }
                $terms[] = trim($term);
            }
            $clean[] = $terms;
        }

        return $clean;
    }

    /**
     * @param list<list<string>> $groups
     * @return list<array<string, mixed>>
     */
    private function errors(array $groups): array
    {
        $errors = [];
        foreach ($groups as $i => $group) {
            $seen = [];
            foreach ($group as $term) {
                $phrase = self::phrase($term);
                if ($phrase === '') {
                    $errors[] = ['code' => 'empty_term', 'group' => $i, 'term' => $term];
                    continue;
                }
                if (mb_strlen($term) > $this->maxTermChars) {
                    $errors[] = [
                        'code' => 'term_too_long', 'group' => $i, 'term' => $term, 'max' => $this->maxTermChars,
                    ];
                }
                if (isset($seen[$phrase])) {
                    $errors[] = ['code' => 'duplicate_term', 'group' => $i, 'term' => $term];
                }
                $seen[$phrase] = true;
            }
            if (count($seen) < 2 && !self::hasError($errors, $i)) {
                $errors[] = ['code' => 'too_few_terms', 'group' => $i];
            }
        }

        return $errors;
    }

    /** @param list<array<string, mixed>> $errors */
    private static function hasError(array $errors, int $group): bool
    {
        foreach ($errors as $error) {
            if ($error['group'] === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<string>> $groups valid
     * @param list<list<string>> $current
     * @return list<array<string, mixed>>
     */
    private function warnings(array $groups, array $current): array
    {
        $before = array_map('serialize', $current);
        $merged = Synonyms::mergeOverlapping($groups);
        $owner = [];   // phrase => indexes of the groups that hold it
        foreach ($groups as $i => $group) {
            foreach ($group as $term) {
                $owner[self::phrase($term)][$i] = true;
            }
        }

        $warnings = [];
        $counted = 0;
        foreach ($groups as $i => $group) {
            if (in_array(serialize($group), $before, true)) {
                continue;
            }
            foreach ($group as $term) {
                $phrase = self::phrase($term);
                $others = array_diff(array_keys($owner[$phrase]), [$i]);
                if ($others !== []) {
                    $warnings[] = [
                        'code' => 'in_other_group', 'group' => $i, 'term' => $term,
                        'merged' => self::mergedGroupOf($merged, $phrase),
                    ];
                }
                if (mb_strlen($phrase) < self::MIN_TERM_CHARS) {
                    $warnings[] = ['code' => 'short_term', 'group' => $i, 'term' => $term];
                }
                if (in_array($phrase, $this->blocklist, true)) {
                    $warnings[] = ['code' => 'blocklisted', 'group' => $i, 'term' => $term];
                }
                if ($this->counter !== null && $counted < self::MAX_COUNTED_TERMS) {
                    $counted++;
                    $matches = ($this->counter)($term);
                    if ($matches > $this->warnMatches) {
                        $warnings[] = [
                            'code' => 'many_matches', 'group' => $i, 'term' => $term,
                            'matches' => $matches, 'limit' => $this->warnMatches,
                        ];
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * @param list<list<string>> $merged
     * @return list<string>
     */
    private static function mergedGroupOf(array $merged, string $phrase): array
    {
        foreach ($merged as $group) {
            if (in_array($phrase, $group, true)) {
                return $group;
            }
        }

        return [];
    }
}
