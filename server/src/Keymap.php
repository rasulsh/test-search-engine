<?php

declare(strict_types=1);

namespace App;

/**
 * Keyboard-layout tolerance. Recovers queries typed with the wrong layout:
 * Persian text typed on a US layout comes out as Latin gibberish (and vice
 * versa). The layout mirrors pipeline/keyword.py's PERSIAN_KEYBOARD.
 */
final class Keymap
{
    /** US-QWERTY key => Persian letter (standard Persian layout). */
    private const EN_TO_FA = [
        'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف',
        'y' => 'غ', 'u' => 'ع', 'i' => 'ه', 'o' => 'خ', 'p' => 'ح',
        'a' => 'ش', 's' => 'س', 'd' => 'ی', 'f' => 'ب', 'g' => 'ل',
        'h' => 'ا', 'j' => 'ت', 'k' => 'ن', 'l' => 'م', 'z' => 'ظ',
        'x' => 'ط', 'c' => 'ز', 'v' => 'ر', 'b' => 'ذ', 'n' => 'د',
        'm' => 'ئ',
    ];

    /** Remap Latin letters to the Persian letters on the same keys. */
    public static function enToFa(string $text): string
    {
        return self::remap($text, self::EN_TO_FA, true);
    }

    /** Remap Persian letters to the Latin letters on the same keys. */
    public static function faToEn(string $text): string
    {
        $map = [];
        foreach (self::EN_TO_FA as $en => $fa) {
            $map[$fa] = $en;
        }

        return self::remap($text, $map, false);
    }

    /**
     * @param array<string, string> $map
     */
    private static function remap(string $text, array $map, bool $lowercaseKeys): string
    {
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $key = $lowercaseKeys ? mb_strtolower($char, 'UTF-8') : $char;
            $out .= $map[$key] ?? $char;
        }

        return $out;
    }
}
