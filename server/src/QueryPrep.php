<?php

declare(strict_types=1);

namespace App;

/**
 * Request-side query hygiene, applied to the text before it is normalized,
 * spell-corrected or sent to the VPS (Normalizer stays a pure, parity-bound
 * function of the text).
 */
final class QueryPrep
{
    /** SQL-injection probes seen in the logs; they get no search at all. */
    private const SUSPICIOUS = '/extractvalue|concat\s*\(\s*0x|union\s+(?:all\s+)?select|sleep\s*\(/i';
    /** "--" is a SQL comment only next to a quote; alone it is a dash in a shopper's query ("ps5 -- slim"). */
    private const COMMENT = '/--/';
    private const QUOTE = '/[\'"`]/';

    /**
     * Decodes the HTML entities OpenCart leaves in query strings ("&amp;amp;",
     * "&#039;"), twice so a double-encoded text is safe, and repairs the forms
     * whose "&" and ";" were already stripped upstream ("Assassin#039s",
     * "ampamp", "ampquot"): those spellings occur in no real query.
     */
    public static function clean(string $raw): string
    {
        $text = $raw;
        for ($pass = 0; $pass < 2; $pass++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }
        $text = (string) preg_replace('/(?<=\pL)#0?39(?=s\b)/u', "'", $text);

        return str_ireplace(['ampquot', 'ampamp'], ['"', '&'], $text);
    }

    public static function isSuspicious(string $text): bool
    {
        return preg_match(self::SUSPICIOUS, $text) === 1
            || (preg_match(self::COMMENT, $text) === 1 && preg_match(self::QUOTE, $text) === 1);
    }

    /**
     * Cuts the query to $maxChars characters and $maxTokens whitespace-separated
     * words, so a pasted paragraph costs no more than a long search.
     *
     * @return array{0: string, 1: bool} the query and whether it was cut
     */
    public static function cap(string $text, int $maxChars, int $maxTokens): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $whole = implode(' ', $words);
        $kept = implode(' ', array_slice($words, 0, max(1, $maxTokens)));
        $capped = trim(mb_substr($kept, 0, max(1, $maxChars), 'UTF-8'));

        return [$capped, $capped !== $whole];
    }
}
