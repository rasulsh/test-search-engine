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
        self::assertSame('x2', Keymap::faToEn('x2'));
        self::assertSame('HF-1502 ب', Keymap::enToFa('HF-1502 f'));
    }

    public function testTrailingDigitsStayWhileTheLettersAreRemapped(): void
    {
        self::assertSame('ل12 ب', Keymap::enToFa('g12 f'));
        self::assertSame('gta ۶', Keymap::faToEn('لفش ۶'));
        self::assertSame('ps۵', Keymap::faToEn('حس۵'));
    }

    public function testPunctuationKeysAreLayoutLetters(): void
    {
        // ISIRI 9147: ; ' , [ ] \ and Shift+C carry ک گ و ج چ پ ژ.
        self::assertSame('کنسول', Keymap::enToFa(';ks,g'));
        self::assertSame('کگوجچپژ', Keymap::enToFa(";',[]\\C"));
        self::assertSame(";ks,g", Keymap::faToEn('کنسول'));
        self::assertSame(';', Keymap::enToFa(';'));
        self::assertSame('a,b', Keymap::faToEn(Keymap::enToFa('a,b')));
    }

    public function testArabicYehAndKafAreUnifiedFirst(): void
    {
        self::assertSame(Keymap::faToEn('سیک'), Keymap::faToEn('سيك'));
        self::assertSame('d', Keymap::faToEn('ي'));
        self::assertSame(';', Keymap::faToEn('ك'));
    }

    public function testPythonAndPhpLayoutTablesAreIdentical(): void
    {
        $script = 'import importlib.util, json, os, sys; sys.path.insert(0, os.path.dirname(sys.argv[1]));'
            . ' spec = importlib.util.spec_from_file_location("kw", sys.argv[1]);'
            . ' m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m);'
            . ' print(json.dumps(m.PERSIAN_KEYBOARD, ensure_ascii=False))';
        $root = dirname(__DIR__, 2);
        $json = shell_exec(
            'python3 -c ' . escapeshellarg($script) . ' ' . escapeshellarg($root . '/pipeline/keyword.py')
        );
        if (!is_string($json) || $json === '') {
            self::markTestSkipped('python3 is not available');
        }
        $python = json_decode($json, true);
        $php = (new \ReflectionClassConstant(Keymap::class, 'EN_TO_FA'))->getValue();
        self::assertSame($python, $php);
    }

    public function testUnknownCharactersUnchanged(): void
    {
        self::assertSame('!؟', Keymap::enToFa('!؟'));
    }
}
