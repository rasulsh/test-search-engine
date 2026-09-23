<?php

declare(strict_types=1);

namespace App;

/**
 * Canonical Persian/English text normalization.
 *
 * MUST stay byte-for-byte identical in output to pipeline/normalize.py
 * (contract 1). Both are verified against fixtures/normalization_cases.json.
 * Bump VERSION here and NORMALIZATION_VERSION there together when rules change.
 *
 * Rules, in order: remove zero-width chars (incl. ZWNJ), remove Arabic diacritics
 * and tatweel, unify Arabic letters to Persian, fold Persian/Arabic-Indic digits,
 * lowercase (Latin), canonicalize model names (drop -, _, ., / between ASCII
 * alphanumerics), collapse whitespace and trim, then map standalone Roman
 * numerals ii..x to digits (whole tokens only, never right after a number
 * token and a space, where "x" / "v" are a dimension or a unit: "2 x 4",
 * "12 v"; "a7 iv" still maps; "i" is never mapped).
 */
final class Normalizer
{
    public const VERSION = 3;

    private const ROMAN = [
        'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6',
        'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10',
    ];

    public static function normalize(?string $text): string
    {
        return (string) preg_replace_callback(
            // A preceding number token is matched (group 1) and left unchanged;
            // mirrors normalize.py, whose lookbehind cannot be variable-width.
            '/(?<![\p{L}\p{N}])([0-9]+ )?(viii|vii|iii|ix|iv|vi|ii|v|x)(?![\p{L}\p{N}])/u',
            static fn (array $m): string => $m[1] !== '' ? $m[0] : self::ROMAN[$m[2]],
            self::canonical($text)
        );
    }

    /**
     * Canonical SKU: normalized text (without the Roman-numeral rule: "X 12" is
     * a code, not "10 12") with every non-letter/non-digit removed, so
     * "AB-12 34", "ab.1234" and "AB1234" are one code. Stricter than the
     * model-name rule, which only drops separators between ASCII characters.
     */
    public static function normalizeSku(?string $sku): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', self::canonical($sku));
    }

    /** Every rule but the Roman numerals, which a code never holds. */
    private static function canonical(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        [$search, $replace] = self::translationTable();
        $text = str_replace($search, $replace, $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('#(?<=[0-9a-z])[-_./]+(?=[0-9a-z])#', '', $text);
        $text = (string) preg_replace('/ +/', ' ', $text);

        return trim($text);
    }

    /**
     * Build the per-character search/replace arrays once. Kept in the exact
     * order of the Python translation table so results match.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function translationTable(): array
    {
        /** @var list<string>|null $search */
        static $search = null;
        /** @var list<string>|null $replace */
        static $replace = null;
        if ($search !== null && $replace !== null) {
            return [$search, $replace];
        }

        $search = [];
        $replace = [];

        // Removed: zero-width chars, Arabic diacritics (harakat U+064B..U+0652,
        // maddah U+0653, hamza above U+0654), superscript alef (U+0670),
        // tatweel (U+0640).
        $remove = [0x200B, 0x200C, 0x200D, 0xFEFF, 0x0670, 0x0640];
        for ($cp = 0x064B; $cp <= 0x0654; $cp++) {
            $remove[] = $cp;
        }
        foreach ($remove as $cp) {
            $search[] = self::chr($cp);
            $replace[] = '';
        }

        // Arabic -> Persian letters (alef forms folded to bare alef).
        $letters = [
            0x064A => 0x06CC, // yeh
            0x0649 => 0x06CC, // alef maksura
            0x0626 => 0x06CC, // yeh with hamza
            0x0643 => 0x06A9, // kaf
            0x0629 => 0x0647, // teh marbuta
            0x06C0 => 0x0647, // heh with yeh above
            0x0622 => 0x0627, // alef with madda
            0x0623 => 0x0627, // alef with hamza above
            0x0625 => 0x0627, // alef with hamza below
            0x0671 => 0x0627, // alef wasla
            0x0624 => 0x0648, // waw with hamza
        ];
        foreach ($letters as $from => $to) {
            $search[] = self::chr($from);
            $replace[] = self::chr($to);
        }

        // Persian and Arabic-Indic digits -> ASCII.
        for ($i = 0; $i < 10; $i++) {
            $search[] = self::chr(0x06F0 + $i);
            $replace[] = (string) $i;
            $search[] = self::chr(0x0660 + $i);
            $replace[] = (string) $i;
        }

        // Whitespace variants -> plain ASCII space.
        $whitespace = [
            0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0xA0, 0x1680,
            0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005,
            0x2006, 0x2007, 0x2008, 0x2009, 0x200A,
            0x2028, 0x2029, 0x202F, 0x205F, 0x3000,
        ];
        foreach ($whitespace as $cp) {
            $search[] = self::chr($cp);
            $replace[] = ' ';
        }

        return [$search, $replace];
    }

    private static function chr(int $codepoint): string
    {
        return mb_chr($codepoint, 'UTF-8');
    }
}
