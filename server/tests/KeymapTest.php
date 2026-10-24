<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keymap;
use PHPUnit\Framework\TestCase;

final class KeymapTest extends TestCase
{
    public function testEnToFaMapsLayout(): void
    {
        // "sony" typed on a Persian layout produces these letters.
        self::assertSame('سخدغ', Keymap::enToFa('sony'));
    }

    public function testFaToEnRecoversLatin(): void
    {
        // The inverse: the Persian gibberish maps back to the intended Latin.
        self::assertSame('sony', Keymap::faToEn('سخدغ'));
    }

    public function testRoundTrip(): void
    {
        self::assertSame('laptop', Keymap::faToEn(Keymap::enToFa('laptop')));
    }

    public function testDigitsAndSpacesPassThrough(): void
    {
        self::assertSame('ل 12 ب', Keymap::enToFa('g 12 f'));
    }

    public function testWordsWithDigitsOrHyphensAreModelCodesAndStay(): void
    {
        self::assertSame('HF-1502', Keymap::enToFa('HF-1502'));
        self::assertSame('dx-130 ل', Keymap::enToFa('dx-130 g'));
        self::assertSame('g12 ب', Keymap::enToFa('g12 f'));
        self::assertSame('x2', Keymap::faToEn('x2'));
    }

    public function testUnknownCharactersUnchanged(): void
    {
        self::assertSame('!؟', Keymap::enToFa('!؟'));
    }
}
