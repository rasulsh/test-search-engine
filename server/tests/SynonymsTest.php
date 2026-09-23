<?php

declare(strict_types=1);

namespace App\Tests;

use App\Synonyms;
use PHPUnit\Framework\TestCase;

final class SynonymsTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
        Synonyms::clearCache();
    }

    private function dataDir(?string $synonyms, ?string $aliases): string
    {
        $dir = sys_get_temp_dir() . '/synonyms_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;
        if ($synonyms !== null) {
            file_put_contents($dir . '/' . Synonyms::SYNONYMS_FILE, $synonyms);
        }
        if ($aliases !== null) {
            file_put_contents($dir . '/' . Synonyms::ALIASES_FILE, $aliases);
        }

        return $dir;
    }

    public function testPhraseAliasExpandsBothWays(): void
    {
        $synonyms = new Synonyms([['GTA', 'Grand Theft Auto', 'جی تی ای']]);

        self::assertSame(
            [['gta', '5'], ['grand', 'theft', 'auto', '5'], ['جی', 'تی', 'ای', '5']],
            $synonyms->variants(['gta', '5'], 10)
        );
        self::assertSame(
            [['grand', 'theft', 'auto', '5'], ['gta', '5'], ['جی', 'تی', 'ای', '5']],
            $synonyms->variants(['grand', 'theft', 'auto', '5'], 10)
        );
    }

    public function testTermsAreNormalizedLikeQueries(): void
    {
        // Persian digits, half-space and a hyphenated model name, as an owner
        // might type them in aliases.json.
        $synonyms = new Synonyms([['PS-5', 'پلی استیشن ۵']]);

        self::assertSame([['ps5'], ['پلی', 'استیشن', '5']], $synonyms->variants(['ps5'], 10));
        self::assertSame(
            [['پلی', 'استیشن', '5', 'slim'], ['ps5', 'slim']],
            $synonyms->variants(['پلی', 'استیشن', '5', 'slim'], 10)
        );
    }

    public function testOnlyWholeTermsMatchNeverSubstrings(): void
    {
        $synonyms = new Synonyms([['gta', 'grand theft auto'], ['auto', 'car']]);

        self::assertSame([['gtax', 'cable']], $synonyms->variants(['gtax', 'cable'], 10));
        self::assertSame([['grand', 'theft']], $synonyms->variants(['grand', 'theft'], 10));
        // The longest phrase wins: "auto" inside "grand theft auto" is not swapped.
        self::assertSame(
            [['grand', 'theft', 'auto'], ['gta']],
            $synonyms->variants(['grand', 'theft', 'auto'], 10)
        );
    }

    public function testVariantsAreCappedWithTheLiteralQueryFirst(): void
    {
        $synonyms = new Synonyms([['a1', 'b1', 'c1', 'd1'], ['x2', 'y2']]);

        self::assertSame([['a1', 'x2']], $synonyms->variants(['a1', 'x2'], 1));
        self::assertSame([['a1', 'x2'], ['b1', 'x2']], $synonyms->variants(['a1', 'x2'], 2));
        self::assertCount(5, $synonyms->variants(['a1', 'x2'], 10));
    }

    public function testNoMatchReturnsOnlyTheQuery(): void
    {
        self::assertSame([['sony']], (new Synonyms([['gta', 'grand theft auto']]))->variants(['sony'], 4));
        self::assertSame([['sony']], (new Synonyms([]))->variants(['sony'], 4));
    }

    public function testParseGroupsRejectsAnythingButAListOfStringLists(): void
    {
        self::assertSame([['a', 'b']], Synonyms::parseGroups('[["a", "b"]]'));
        self::assertSame([], Synonyms::parseGroups('[]'));
        self::assertNull(Synonyms::parseGroups('[["a", "b"],]'));
        self::assertNull(Synonyms::parseGroups('{"groups": [["a", "b"]]}'));
        self::assertNull(Synonyms::parseGroups('[["a", 5]]'));
        self::assertNull(Synonyms::parseGroups('["a", "b"]'));
    }

    public function testDirectoryMergesSynonymsAndAliasesAndCapsGeneratedGroups(): void
    {
        $dir = $this->dataDir(
            '[["laptop", "لپ تاپ"], ["a", "b", "c", "d", "e"]]',
            '[["gta", "grand theft auto"], ["x1", "x2", "x3", "x4", "x5"]]'
        );
        $synonyms = Synonyms::fromDirectory($dir, 4);

        self::assertSame([['laptop'], ['لپ', 'تاپ']], $synonyms->variants(['laptop'], 10));
        self::assertSame([['gta'], ['grand', 'theft', 'auto']], $synonyms->variants(['gta'], 10));
        // A generated group over the cap is ignored; an owner group never is.
        self::assertSame([['a']], $synonyms->variants(['a'], 10));
        self::assertCount(5, $synonyms->variants(['x1'], 10));
    }

    public function testMissingOrMalformedFilesExpandNothing(): void
    {
        self::assertSame([['gta']], Synonyms::fromDirectory($this->dataDir(null, null), 4)->variants(['gta'], 4));
        self::assertSame(
            [['gta']],
            Synonyms::fromDirectory($this->dataDir('not json', '[["gta"'), 4)->variants(['gta'], 4)
        );
    }

    public function testAnEditedAliasFileIsPickedUp(): void
    {
        $dir = $this->dataDir(null, '[["gta", "grand theft auto"]]');
        self::assertCount(2, Synonyms::fromDirectory($dir, 4)->variants(['gta'], 4));

        // Replaced as the reload swap does: a new file (new inode).
        $path = $dir . '/' . Synonyms::ALIASES_FILE;
        file_put_contents($path . '.tmp', '[["gta", "grand theft auto", "جی تی ای"]]');
        rename($path . '.tmp', $path);

        self::assertCount(3, Synonyms::fromDirectory($dir, 4)->variants(['gta'], 4));
    }
}
