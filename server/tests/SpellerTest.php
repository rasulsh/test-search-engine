<?php

declare(strict_types=1);

namespace App\Tests;

use APCUIterator;
use App\Speller;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SpellerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }
        $this->tempDirs = [];
        self::resetProcessCache();
    }

    private function speller(): Speller
    {
        $vocabulary = Speller::buildVocabulary([
            'sony wireless headphones',
            'sony alpha camera',
            'دوربین سونی آلفا',
            'گوشی سامسونگ گلکسی',
        ]);

        return new Speller($vocabulary);
    }

    public function testBuildVocabularyCountsTokens(): void
    {
        $vocabulary = Speller::buildVocabulary(['sony sony camera']);

        self::assertSame(2, $vocabulary['sony']);
        self::assertSame(1, $vocabulary['camera']);
    }

    public function testCorrectsLatinTypo(): void
    {
        self::assertSame('sony camera', $this->speller()->suggest('sont camera'));
    }

    public function testCorrectsPersianTypo(): void
    {
        // "دوربان" -> "دوربین" (one substitution).
        self::assertSame('دوربین', $this->speller()->suggest('دوربان'));
    }

    public function testReturnsNullWhenAllTokensKnown(): void
    {
        self::assertNull($this->speller()->suggest('sony camera'));
    }

    public function testLeavesDigitsAndUnrecoverableTokens(): void
    {
        // Pure digits are kept; a token with no near match stays as-is, so the
        // whole query yields no suggestion.
        self::assertNull($this->speller()->suggest('zzzzz 123'));
    }

    public function testRareCandidatesAreNotSuggested(): void
    {
        // "اغازین" (seen in one product) is as close to the typo as "اساسین"
        // (seen in five); only the latter passes a minimum frequency of 2.
        $vocabulary = ['اغازین' => 1, 'اساسین' => 5, 'کرید' => 1];

        self::assertSame('اساسین', (new Speller($vocabulary, 2, null, 2))->suggest('اصاصین'));
        self::assertNull((new Speller(['اغازین' => 1], 2, null, 2))->suggest('اصاصین'));
        // A rare term is still a KNOWN word: it is never "corrected" away.
        self::assertNull((new Speller($vocabulary, 2, null, 2))->suggest('کرید'));
    }

    public function testShortTokensAllowOnlyOneEdit(): void
    {
        $speller = new Speller(['sony' => 5, 'camera' => 5], 2);

        self::assertNull($speller->suggest('siiy'));          // 2 edits on 4 chars
        self::assertSame('sony', $speller->suggest('sonu'));  // 1 edit
        self::assertSame('camera', $speller->suggest('cumara')); // 2 edits on 6 chars
        self::assertNull((new Speller(['camera' => 5], 1))->suggest('cumara')); // max distance 1
        // A swapped neighbour pair is one slip, whatever the maximum distance.
        self::assertSame('camera', (new Speller(['camera' => 5], 1))->suggest('camrea'));
    }

    public function testADistanceTwoJumpToAnUnrelatedWordIsRejectedByDefault(): void
    {
        // Two edits from each other, but different words: a wrong "did you mean" is worse than none.
        $speller = new Speller(['اسپیکر' => 9, 'میکروفن' => 9, 'headset' => 9]);

        self::assertNull($speller->suggest('اسپکیرر'));   // one swap away would be fine; here two substitutions
        self::assertNull($speller->suggest('headdat'));    // 2 edits on a 7-letter Latin word: below the long-word rule
        self::assertNull((new Speller(['camera' => 5]))->suggest('cumara'));
        // The same pair is still reachable when the owner raises the distance explicitly.
        self::assertSame('camera', (new Speller(['camera' => 5], 2))->suggest('cumara'));
    }

    public function testALongLatinTypoStillTakesTwoEditsWhenTheFirstLetterHolds(): void
    {
        $speller = new Speller(['playstation' => 9, 'keyboard' => 9]);

        self::assertSame('playstation', $speller->suggest('plastatian'));  // 2 edits
        self::assertSame('keyboard', $speller->suggest('kaybourd'));       // 8 letters, 2 edits, same first letter
        self::assertNull($speller->suggest('blaystatian'));                // first letter differs: no jump
        self::assertNull($speller->suggest('keyboa'));                     // dropped letters: never a correction
        // Persian never takes the long-word exception.
        self::assertNull((new Speller(['کیبوردگیمینگ' => 9]))->suggest('کیبرداگیمینگ'));
    }

    public function testASwappedPairBeatsAnEquallyDistantWord(): void
    {
        // Plain Levenshtein ties "battle" and "battery" at 2 and picks the frequent one.
        $speller = new Speller(['battery' => 50, 'battle' => 3]);

        self::assertSame('battle', $speller->suggest('battel'));
    }

    public function testShortTokensAndCodesAreNeverCorrected(): void
    {
        $speller = new Speller(['mwe' => 40, 'atari' => 30, 'ps5' => 90, 'مدل' => 9]);

        self::assertNull($speller->suggest('mw4'));  // a code, one edit from a word
        self::assertNull($speller->suggest('ps4'));
    }

    public function testACandidateShouldShareTheFirstLetterOfAShortTypo(): void
    {
        $speller = new Speller(['atari' => 30]);

        self::assertNull($speller->suggest('gtavi'));
        // A long token may hit its first letter wrong.
        self::assertSame('playstation', (new Speller(['playstation' => 9]))->suggest('blaystation'));
    }

    public function testADroppedLetterWordIsNotACorrection(): void
    {
        $speller = new Speller(['کالا' => 500, 'باب' => 200]);

        self::assertNull($speller->suggest('کالاف')); // "کالا" is what is left after dropping a letter
        self::assertNull($speller->suggest('لبتاب'));
    }

    public function testBlockedGenericWordsAreNotOffered(): void
    {
        $speller = new Speller(['item' => 300, 'redd' => 2, 'reds' => 5]);

        self::assertSame('item', $speller->suggest('itam'));
        self::assertNull($speller->withBlocked(['item'])->suggest('itam'));
        // The next best candidate is offered when the best is blocked.
        self::assertSame('reds', $speller->suggest('redz'));
        self::assertSame('redd', $speller->withBlocked(['reds'])->suggest('redz'));
        // withBlocked() leaves the original speller alone.
        self::assertSame('item', $speller->suggest('itam'));
    }

    public function testParseDictionaryReadsPipelineFormat(): void
    {
        // Format written by pipeline/keyword.py: "token<TAB>count", trailing newline.
        $vocabulary = Speller::parseDictionary("sony\t4\nدوربین\t2\n2024\t1\nbad line\nx\tnan\n\t3\nlaptop\t1\r\n\n");

        self::assertSame(['sony' => 4, 'دوربین' => 2, 2024 => 1, 'laptop' => 1], $vocabulary);
    }

    public function testFromDictionaryReturnsNullWhenFileMissing(): void
    {
        self::assertNull(Speller::fromDictionary($this->tempDir() . '/spellcheck.txt', false));
    }

    public function testFromDictionaryIsCachedPerWorker(): void
    {
        $path = $this->tempDir() . '/spellcheck.txt';
        file_put_contents($path, "sony\t4\ncamera\t2\n");

        $first = Speller::fromDictionary($path, false);
        self::assertNotNull($first);
        self::assertSame('sony camera', $first->suggest('sont camera'));
        self::assertSame($first, Speller::fromDictionary($path, false), 'warm call must reuse the parsed speller');
    }

    public function testSwappedDictionaryIsPickedUpWithoutExplicitReload(): void
    {
        // Mirrors the reload swap: a new file is renamed into the same path.
        $dir = $this->tempDir();
        $path = $dir . '/spellcheck.txt';
        file_put_contents($path, "sony\t4\n");
        self::assertSame('sony', Speller::fromDictionary($path, false)?->suggest('sont'));

        file_put_contents($dir . '/next.txt', "sent\t4\n");
        rename($dir . '/next.txt', $path);

        self::assertSame('sent', Speller::fromDictionary($path, false)?->suggest('sont'));
    }

    public function testApcuSharesDictionaryAndReloadEvictsStaleEntry(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled for this SAPI (set apc.enable_cli=1)');
        }
        $dir = $this->tempDir();
        $path = $dir . '/spellcheck.txt';
        file_put_contents($path, "sony\t4\n");
        Speller::reloadDictionary($path, true);
        self::assertSame(1, $this->apcuEntries());

        // A fresh worker (empty process cache) is served from APCu.
        self::resetProcessCache();
        self::assertSame('sony', Speller::fromDictionary($path, true)?->suggest('sont'));

        // After a swap, reload leaves exactly one entry: the new dictionary.
        file_put_contents($dir . '/next.txt', "sent\t4\n");
        rename($dir . '/next.txt', $path);
        self::assertSame('sent', Speller::reloadDictionary($path, true)?->suggest('sont'));
        self::assertSame(1, $this->apcuEntries());
    }

    public function testMatchesReferenceEditDistanceIncludingLargeAlphabets(): void
    {
        // The fast path encodes characters to bytes for native levenshtein();
        // it must pick exactly what a plain multibyte Levenshtein would, also
        // when the alphabet exceeds the 255 byte codes (fallback path).
        mt_srand(20260922);
        $persian = mb_str_split('ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی');
        $latin = str_split('abcde0123');
        $cjk = [];
        for ($c = 0x4E00; $c < 0x4E00 + 300; $c++) {
            $cjk[] = mb_chr($c);
        }

        foreach ([$persian, $latin, array_merge($persian, $latin), array_merge($persian, $cjk)] as $alphabet) {
            $vocabulary = [];
            while (count($vocabulary) < 400) {
                $vocabulary[$this->randomWord($alphabet, 1, 8)] = mt_rand(1, 5);
            }
            foreach ([1, 3] as $minFrequency) {
                $speller = new Speller($vocabulary, 2, null, $minFrequency);
                for ($q = 0; $q < 40; $q++) {
                    $query = $this->randomWord($alphabet, 1, 9) . ' ' . $this->randomWord($alphabet, 1, 9);
                    self::assertSame(
                        self::referenceSuggest($vocabulary, $query, $minFrequency),
                        $speller->suggest($query),
                        $query
                    );
                }
            }
        }
    }

    private static function vocabularyOf(Speller $speller): array
    {
        return (new ReflectionProperty(Speller::class, 'vocabulary'))->getValue($speller);
    }

    public function testAnAliasWordIsKnownAndNoLongerCorrected(): void
    {
        $base = $this->speller();
        // "sont" is one edit from the catalog word "sony": without the overlay it is "fixed".
        self::assertSame('sony camera', $base->suggest('sont camera'));

        $overlay = $base->withExtraWords(['sont'], Speller::ALIAS_TOKEN_COUNT, 'v1');
        self::assertNull($overlay->suggest('sont camera'));
    }

    public function testAnAliasWordCanBeOfferedAsTheCorrectionOfATypo(): void
    {
        $base = $this->speller();
        self::assertNull($base->suggest('wolverin'));

        $overlay = $base->withExtraWords(['wolverine', 'ولورینگ'], Speller::ALIAS_TOKEN_COUNT, 'v1');
        self::assertSame('wolverine', $overlay->suggest('wolverin'));
        self::assertSame('ولورینگ', $overlay->suggest('ولورینک'));
    }

    public function testOnlyWordsBelowTheCountAreRaisedAndOneCharacterTokensAreSkipped(): void
    {
        $base = new Speller(Speller::buildVocabulary(['camera camera camera camera camera camera sony']));
        $overlay = $base->withExtraWords(['camera', 'sony', 'a', 'wolverine'], 5, 'v1');
        $vocabulary = self::vocabularyOf($overlay);

        self::assertSame(6, $vocabulary['camera'], 'a more frequent catalog word keeps its own count');
        self::assertSame(5, $vocabulary['sony']);
        self::assertSame(5, $vocabulary['wolverine']);
        self::assertArrayNotHasKey('a', $vocabulary);
    }

    public function testTheBaseSpellerIsNeverModified(): void
    {
        $base = $this->speller();
        $before = self::vocabularyOf($base);
        $base->withExtraWords(['wolverine'], 5, 'v1');

        self::assertSame($before, self::vocabularyOf($base));
        self::assertNull($base->suggest('wolverin'));
    }

    public function testNoAliasWordsReturnsTheSameSpeller(): void
    {
        $base = $this->speller();
        self::assertSame($base, $base->withExtraWords([], 5, 'v1'));
    }

    public function testTheOverlayIsCachedPerBaseAndVersionAndRebuiltFromTheBaseOnANewVersion(): void
    {
        $base = $this->speller();
        $first = $base->withExtraWords(['wolverine'], 5, 'v1');
        self::assertSame($first, $base->withExtraWords(['wolverine'], 5, 'v1'));

        // The owner deleted the term and added another: the new overlay must not remember the old word.
        $second = $base->withExtraWords(['venom'], 5, 'v2');
        self::assertNotSame($first, $second);
        self::assertNull($second->suggest('wolverin'));
        self::assertSame('venom', $second->suggest('venon'));
        self::assertArrayNotHasKey('wolverine', self::vocabularyOf($second));
    }

    public function testAnAliasOverlayHonoursTheBlocklist(): void
    {
        $overlay = $this->speller()->withExtraWords(['wolverine'], 5, 'v1')->withBlocked(['wolverine']);
        self::assertNull($overlay->suggest('wolverin'));
    }

    private function tempDir(): string
    {
        $dir = TempTree::path('speller');
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function apcuEntries(): int
    {
        return iterator_count(new APCUIterator('/^search\.spell\./'));
    }

    private static function resetProcessCache(): void
    {
        (new ReflectionProperty(Speller::class, 'processCache'))->setValue(null, []);
        (new ReflectionProperty(Speller::class, 'instances'))->setValue(null, []);
    }

    /** @param list<string> $alphabet */
    private function randomWord(array $alphabet, int $min, int $max): string
    {
        $word = '';
        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            $word .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $word;
    }

    /**
     * Straightforward spec of suggest(): per unknown token, the closest term
     * seen at least $minFrequency times within the threshold (1 edit for
     * tokens of up to 4 characters, else 2), ties by higher frequency then
     * lexical order.
     *
     * @param array<string, int> $vocabulary
     */
    private static function referenceSuggest(array $vocabulary, string $query, int $minFrequency): ?string
    {
        $out = [];
        $changed = false;
        foreach (explode(' ', $query) as $token) {
            if (isset($vocabulary[$token]) || ctype_digit($token) || mb_strlen($token) < 4) {
                $out[] = $token;
                continue;
            }
            $threshold = mb_strlen($token) <= 4 ? 1 : 2;
            $best = null;
            // A swapped neighbour pair wins: the most frequent such word.
            $chars = mb_str_split($token);
            $swap = null;
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $swapped = $chars;
                [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
                $word = implode('', $swapped);
                $frequency = $vocabulary[$word] ?? 0;
                if ($frequency >= $minFrequency && ($swap === null || $frequency > $vocabulary[$swap])) {
                    $swap = $word;
                }
            }
            if ($swap !== null) {
                $out[] = $swap;
                $changed = true;
                continue;
            }
            foreach ($vocabulary as $term => $frequency) {
                $term = (string) $term;
                $distance = self::referenceDistance($token, $term);
                if ($distance > $threshold || $frequency < $minFrequency || str_contains($token, $term)) {
                    continue;
                }
                if (count($chars) <= 6 && mb_substr($term, 0, 1) !== $chars[0]) {
                    continue;
                }
                $key = [$distance, -$frequency, $term];
                if ($best === null || $key < $best) {
                    $best = $key;
                }
            }
            $out[] = $best === null ? $token : $best[2];
            $changed = $changed || $best !== null;
        }
        $suggestion = implode(' ', $out);

        return $changed && $suggestion !== $query ? $suggestion : null;
    }

    private static function referenceDistance(string $a, string $b): int
    {
        $ca = mb_str_split($a);
        $cb = mb_str_split($b);
        $row = range(0, count($cb));
        foreach ($ca as $i => $x) {
            $next = [$i + 1];
            foreach ($cb as $j => $y) {
                $next[] = min($row[$j + 1] + 1, $next[$j] + 1, $row[$j] + ($x === $y ? 0 : 1));
            }
            $row = $next;
        }

        return $row[count($cb)];
    }
}
