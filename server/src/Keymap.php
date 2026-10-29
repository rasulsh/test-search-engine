<?php

declare(strict_types=1);

namespace App;

/**
 * Keyboard-layout tolerance. Recovers queries typed with the wrong layout:
 * Persian text typed on a US layout comes out as Latin gibberish (and vice
 * versa). The layout (standard Windows Persian, ISIRI 9147) mirrors
 * pipeline/keyword.py's PERSIAN_KEYBOARD. A word is remapped when it is made of
 * letters and the layout's punctuation keys, optionally followed by digits ("gta6");
 * a hyphenated or digit-led model code ("HF-1502") is no mistyped Persian.
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
        ';' => 'ک', "'" => 'گ', ',' => 'و', '[' => 'ج', ']' => 'چ', '\\' => 'پ',
        'C' => 'ژ',
    ];

    /** Arabic variants a Persian keyboard may produce, unified before the remap. */
    private const ARABIC_FORMS = ['ي' => 'ی', 'ك' => 'ک'];

    /** Remap Latin letters to the Persian letters on the same keys. */
    public static function enToFa(string $text): string
    {
        return self::remap($text, self::EN_TO_FA);
    }

    /** Remap Persian letters to the Latin letters on the same keys. */
    public static function faToEn(string $text): string
    {
        $map = [];
        foreach (self::EN_TO_FA as $en => $fa) {
            $map[$fa] = $en;
        }

        return self::remap($text, $map);
    }

    /**
     * @param array<string, string> $map key => replacement; a key that is not a letter is a punctuation key
     */
    private static function remap(string $text, array $map): string
    {
        $text = strtr($text, self::ARABIC_FORMS);
        $punctuation = '';
        foreach (array_keys($map) as $key) {
            if (preg_match('/^\p{L}$/u', (string) $key) !== 1) {
                $punctuation .= preg_quote((string) $key, '/');
            }
        }
        $shape = '/^[\p{L}' . $punctuation . ']*\p{L}[\p{L}' . $punctuation . ']*\p{N}*$/u';

        $out = '';
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $word) {
            if (preg_match($shape, $word) !== 1) {
                $out .= $word;
                continue;
            }
            foreach (mb_str_split($word) as $char) {
                $out .= $map[$char] ?? $map[mb_strtolower($char, 'UTF-8')] ?? $char;
            }
        }

        return $out;
    }
}
