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
        self::assertSame('ل12 ب', Keymap::enToFa('g12 f'));
    }

    public function testUnknownCharactersUnchanged(): void
    {
        self::assertSame('!؟', Keymap::enToFa('!؟'));
    }
}
