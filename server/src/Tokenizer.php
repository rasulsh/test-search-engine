<?php

declare(strict_types=1);

namespace App;

/**
 * Splits text into tokens on non-letter/non-digit boundaries (Unicode). Shared
 * by keyword search, the speller, and vocabulary building so token edges agree,
 * and mirrors the pipeline tokenizer (keyword.py).
 */
final class Tokenizer
{
    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }
}
