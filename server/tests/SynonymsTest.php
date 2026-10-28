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
            TempTree::remove($dir);
        }
        Synonyms::clearCache();
    }

    private function dataDir(?string $synonyms, ?string $aliases): string
    {
        $dir = TempTree::path('synonyms');
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

    public function testAliasesCanComeFromAnotherFileThanTheBundle(): void
    {
        $dir = $this->dataDir(null, '[["gta", "bundle only"]]');
        $storage = $this->dataDir(null, '[["gta", "grand theft auto"]]');
        $storageFile = $storage . '/' . Synonyms::ALIASES_FILE;

        $fromStorage = Synonyms::fromDirectory($dir, 4, $storageFile);
        self::assertSame([['gta'], ['grand', 'theft', 'auto']], $fromStorage->variants(['gta'], 4));
        // The default stays the bundle's own file.
        self::assertSame([['gta'], ['bundle', 'only']], Synonyms::fromDirectory($dir, 4)->variants(['gta'], 4));

        // A save through the panel changes the file: the next search sees it (the version changes with it).
        $version = $fromStorage->version();
        file_put_contents($storageFile, '[["gta", "جی تی ای"]]');
        $next = Synonyms::fromDirectory($dir, 4, $storageFile);
        self::assertNotSame($version, $next->version());
        self::assertSame([['gta'], ['جی', 'تی', 'ای']], $next->variants(['gta'], 4));
    }

    public function testAliasTokensAreTheTokensOfTheMergedOwnerGroupsOnly(): void
    {
        $dir = $this->dataDir('[["laptop", "لپ تاپ"]]', '[["ps5", "پلی استیشن 5"], ["PS5", "پی اس فایو"]]');
        $tokens = Synonyms::fromDirectory($dir, 4)->aliasTokens();

        self::assertEqualsCanonicalizing(['ps5', 'پلی', 'استیشن', '5', 'پی', 'اس', 'فایو'], $tokens);
        self::assertSame([], (new Synonyms([['a', 'b']]))->aliasTokens());
    }

    public function testAMissingAliasFileMeansNoAliasTokens(): void
    {
        $dir = $this->dataDir(null, null);
        self::assertSame([], Synonyms::fromDirectory($dir, 4, $dir . '/nope.json')->aliasTokens());
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

    public function testVersionChangesWithTheFilesContentOnly(): void
    {
        $dir = $this->dataDir(null, '[["mafia","مافیا"]]');
        $first = Synonyms::fromDirectory($dir, 4)->version();
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first);

        self::assertSame($first, Synonyms::fromDirectory($dir, 4)->version());

        // A same-size edit within the same second still changes the version and the groups.
        $path = $dir . '/' . Synonyms::ALIASES_FILE;
        $mtime = (int) filemtime($path);
        file_put_contents($path, '[["mafia","مافیه"]]');
        touch($path, $mtime);
        $second = Synonyms::fromDirectory($dir, 4)->version();

        self::assertNotSame($first, $second);
        self::assertSame(
            [['مافیه'], ['mafia']],
            Synonyms::fromDirectory($dir, 4)->variants(['مافیه'], 4)
        );
    }

    public function testAnEditedAliasFileIsPickedUpByTheNextCall(): void
    {
        $dir = $this->dataDir(null, '[]');
        self::assertCount(1, Synonyms::fromDirectory($dir, 4)->variants(['مافیا'], 4));

        file_put_contents($dir . '/' . Synonyms::ALIASES_FILE, '[["mafia","مافیا"]]');

        self::assertSame(
            [['مافیا'], ['mafia']],
            Synonyms::fromDirectory($dir, 4)->variants(['مافیا'], 4)
        );
    }

    public function testSpacingVariantFindsTheAliasWrittenWithOtherSpacing(): void
    {
        $synonyms = new Synonyms([['Resident Evil', 'رزیدنت اویل']]);

        self::assertSame('رزیدنت اویل', $synonyms->spacingVariant('رزیدنتاویل'));
        self::assertSame('resident evil', $synonyms->spacingVariant('residentevil'));
        self::assertNull($synonyms->spacingVariant('resident evil'), 'the same spelling is no variant');
        self::assertNull($synonyms->spacingVariant('resident'));
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
        // The literal query, 3 + 1 single swaps, then the 3 x 1 both-swapped variants.
        self::assertCount(8, $synonyms->variants(['a1', 'x2'], 10));
    }

    public function testEverySpanSwappedVariantFollowsTheSingleSwaps(): void
    {
        $synonyms = new Synonyms([['مدرن', 'modern'], ['وارفار', 'warfare']]);

        self::assertSame(
            [['مدرن', 'وارفار'], ['modern', 'وارفار'], ['مدرن', 'warfare'], ['modern', 'warfare']],
            $synonyms->variants(['مدرن', 'وارفار'], 10)
        );
        // The cap keeps the legacy single swaps first; the combined one is only an extra.
        self::assertSame(
            [['مدرن', 'وارفار'], ['modern', 'وارفار'], ['مدرن', 'warfare']],
            $synonyms->variants(['مدرن', 'وارفار'], 3)
        );
        // Words between the spans and phrase spans keep their place.
        self::assertSame(
            ['gta', 'x', 'ps5'],
            (new Synonyms([['gta', 'grand theft auto'], ['ps5', 'playstation 5']]))->variants(['gta', 'x', 'ps5'], 1)[0]
        );
        self::assertContains(
            ['grand', 'theft', 'auto', 'x', 'playstation', '5'],
            (new Synonyms([['gta', 'grand theft auto'], ['ps5', 'playstation 5']]))->variants(['gta', 'x', 'ps5'], 10)
        );
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

    public function testABlocklistedTermDropsABuildDerivedGroupButNeverAnOwnerGroup(): void
    {
        $dir = $this->dataDir(
            '[["laptop", "لپ تاپ"], ["mouse", "موس", "ماوس"]]',
            '[["mouse", "موش کامپیوتر"]]'
        );

        $plain = Synonyms::fromDirectory($dir, 4);
        $blocked = Synonyms::fromDirectory($dir, 4, null, ' Mouse , ,  ');

        self::assertSame([['laptop'], ['لپ', 'تاپ']], $blocked->variants(['laptop'], 10), 'other groups are kept');
        // The generated mouse group is gone; the owner's group with the same term still expands.
        self::assertSame([['mouse'], ['موش', 'کامپیوتر']], $blocked->variants(['mouse'], 10));
        self::assertSame([], array_values(array_filter(
            $blocked->variants(['موس'], 10),
            static fn (array $v): bool => $v !== ['موس']
        )));
        self::assertGreaterThan(2, count($plain->variants(['mouse'], 10)));
    }

    public function testTheBlocklistIsPartOfTheVersionSoTheResultCacheKeyChanges(): void
    {
        $dir = $this->dataDir('[["mouse", "موس"]]', '[]');

        $none = Synonyms::fromDirectory($dir, 4)->version();
        $a = Synonyms::fromDirectory($dir, 4, null, 'mouse')->version();
        $b = Synonyms::fromDirectory($dir, 4, null, 'mouse,keyboard')->version();

        self::assertNotSame($none, $a);
        self::assertNotSame($a, $b);
        // Order, case and blanks do not matter.
        self::assertSame($b, Synonyms::fromDirectory($dir, 4, null, ' Keyboard,, mouse ')->version());
    }

    public function testALetterDigitNameMeetsItsOtherSpellingInAnAliasGroup(): void
    {
        $spaced = new Synonyms([['gta 6', 'جی تی ای 6']]);
        $joined = new Synonyms([['fc27', 'اف سی 27']]);

        // "gta6" joined in the query, "gta 6" in the group, and the reverse.
        self::assertSame([['gta6'], ['gta', '6'], ['جی', 'تی', 'ای', '6']], $spaced->variants(['gta6'], 10));
        self::assertSame([['fc', '27'], ['fc27'], ['اف', 'سی', '27']], $joined->variants(['fc', '27'], 10));
        // Inside a longer query only the name is swapped.
        self::assertContains(['buy', 'gta', '6'], $spaced->variants(['buy', 'gta6'], 10));
    }

    public function testLetterDigitVariantsNeedTheShapeAndAnAliasThatHoldsTheOtherSpelling(): void
    {
        $synonyms = new Synonyms([['rtx 4060', 'آر تی ایکس 4060'], ['ps 5', 'پلی استیشن 5']]);

        self::assertSame(
            [['rtx4060'], ['rtx', '4060'], ['ار', 'تی', 'ایکس', '4060']],
            $synonyms->variants(['rtx4060'], 10)
        );
        self::assertSame([['gta6']], $synonyms->variants(['gta6'], 10), 'no alias holds "gta 6"');
        self::assertSame([['abcdefg1']], $synonyms->variants(['abcdefg1'], 10), 'over five letters is not the shape');
        self::assertSame([['a1']], $synonyms->variants(['a1'], 10), 'one letter is not the shape');
        self::assertSame([['rtx', '12345']], $synonyms->variants(['rtx', '12345'], 10), 'five digits is not the shape');
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

    public function testOverlappingOwnerGroupsAreMerged(): void
    {
        $merged = Synonyms::mergeOverlapping([
            ['ps5', 'ps 5'],
            ['x', 'y'],
            ['پی اس فایو', 'PS5'],
            ['y', 'ps 5'], // bridges the first and second groups
            ['lone', 'solo'],
        ]);

        self::assertCount(2, $merged);
        sort($merged[0]);
        self::assertSame(['ps 5', 'ps5', 'x', 'y', 'پی اس فایو'], $merged[0]);
        self::assertSame(['lone', 'solo'], $merged[1]);
    }
}
