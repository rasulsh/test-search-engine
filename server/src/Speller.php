<?php

declare(strict_types=1);

namespace App;

/**
 * "Did you mean" spelling correction over a token vocabulary derived from the
 * catalog. Works for Persian and Latin (multibyte-aware edit distance).
 *
 * In M2 the vocabulary is built from the products table; M3's bundle ships a
 * precomputed spellcheck dictionary that can replace it.
 */
final class Speller
{
    /** @var array<string, int> token => frequency */
    private array $vocabulary;
    private int $maxDistance;

    /**
     * @param array<string, int> $vocabulary
     */
    public function __construct(array $vocabulary, int $maxDistance = 2)
    {
        $this->vocabulary = $vocabulary;
        $this->maxDistance = max(1, $maxDistance);
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
        $length = mb_strlen($token);
        // Be stricter on short tokens to avoid absurd corrections.
        $threshold = $length <= 3 ? 1 : $this->maxDistance;

        $bestTerm = null;
        $bestDistance = PHP_INT_MAX;
        $bestFrequency = -1;

        foreach ($this->vocabulary as $term => $frequency) {
            if (abs(mb_strlen((string) $term) - $length) > $threshold) {
                continue;
            }
            $distance = self::distance($token, (string) $term);
            if ($distance > $threshold) {
                continue;
            }

            $better = $distance < $bestDistance
                || ($distance === $bestDistance && $frequency > $bestFrequency)
                || ($distance === $bestDistance && $frequency === $bestFrequency
                    && ($bestTerm === null || (string) $term < $bestTerm));

            if ($better) {
                $bestTerm = (string) $term;
                $bestDistance = $distance;
                $bestFrequency = $frequency;
            }
        }

        return $bestTerm;
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
}
