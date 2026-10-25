<?php

declare(strict_types=1);

namespace App\Tests;

use App\AliasValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AliasValidatorTest extends TestCase
{
    private function validator(?callable $counter = null, array $blocklist = []): AliasValidator
    {
        return new AliasValidator(20, 100, $blocklist, $counter);
    }

    /** @param array{errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} $result */
    private static function codes(array $result, string $kind): array
    {
        return array_column($result[$kind], 'code');
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedShapes(): iterable
    {
        yield 'a string' => ['gta'];
        yield 'an object' => [['a' => ['x', 'y']]];
        yield 'a group that is a string' => [['gta']];
        yield 'a group that is an object' => [[['a' => 'x', 'b' => 'y']]];
        yield 'a term that is a number' => [[['gta', 5]]];
        yield 'a term that is null' => [[['gta', null]]];
        yield 'a nested list' => [[[['gta'], 'x']]];
    }

    #[DataProvider('malformedShapes')]
    public function testAnythingButAListOfListsOfStringsIsAShapeError(mixed $groups): void
    {
        $result = $this->validator()->validate($groups, []);
        self::assertSame(['shape'], self::codes($result, 'errors'));
        self::assertSame([], $result['groups']);
    }

    public function testDecodeRejectsInvalidJsonAndObjects(): void
    {
        self::assertSame(['shape'], array_column(AliasValidator::decode('[["a","b"],]')['errors'], 'code'));
        self::assertSame(['shape'], array_column(AliasValidator::decode('{"a":["x"]}')['errors'], 'code'));
        self::assertSame([['a', 'b']], AliasValidator::decode('[["a","b"]]')['groups']);
    }

    public function testAnEmptyTermIsAnError(): void
    {
        $result = $this->validator()->validate([['gta', '   ']], []);
        self::assertSame(['empty_term'], self::codes($result, 'errors'));
        self::assertSame(0, $result['errors'][0]['group']);
    }

    public function testATermThatNormalizesToNothingIsAnError(): void
    {
        // Only a ZWNJ and a bidi mark: nothing the search could ever match.
        $result = $this->validator()->validate([['gta', "\u{200C}\u{200F}"]], []);
        self::assertContains('empty_term', self::codes($result, 'errors'));
    }

    public function testAGroupNeedsTwoDistinctNormalizedTerms(): void
    {
        self::assertSame(['too_few_terms'], self::codes($this->validator()->validate([['gta']], []), 'errors'));
        self::assertSame(['too_few_terms'], self::codes($this->validator()->validate([[]], []), 'errors'));
        // "GTA" and "gta" are one term once normalized: reported as the duplicate it is (one message per group).
        self::assertSame(['duplicate_term'], self::codes($this->validator()->validate([['GTA', 'gta']], []), 'errors'));
    }

    public function testDuplicateTermsAfterNormalizationAreAnError(): void
    {
        $result = $this->validator()->validate([['ps5', 'پلی استیشن ۵', 'PS5', 'پلی استیشن 5']], []);
        self::assertSame(['duplicate_term', 'duplicate_term'], self::codes($result, 'errors'));
    }

    public function testATermLongerThanTheConfiguredMaximumIsAnError(): void
    {
        $result = $this->validator()->validate([['gta', str_repeat('a', 21)]], []);
        self::assertSame(['term_too_long'], self::codes($result, 'errors'));
        self::assertSame(20, $result['errors'][0]['max']);
        self::assertSame([], $this->validator()->validate([['gta', str_repeat('a', 20)]], [])['errors']);
    }

    public function testErrorsBlockAndWarningsAreNotComputedForABrokenProposal(): void
    {
        $result = $this->validator(static fn () => 9999)->validate([['gta'], ['ps5', 'پلی استیشن']], []);
        self::assertSame([], $result['warnings']);
        self::assertSame([], $result['groups']);
    }

    public function testAValidProposalIsReturnedTrimmedAndAsTyped(): void
    {
        $result = $this->validator()->validate([[' GTA ', "Grand Theft Auto\n"]], []);
        self::assertSame([], $result['errors']);
        self::assertSame([['GTA', 'Grand Theft Auto']], $result['groups']);
    }

    public function testATermInAnotherGroupWarnsAndShowsTheMergedGroup(): void
    {
        $current = [['ps5', 'پلی استیشن 5']];
        $result = $this->validator()->validate([['ps5', 'پلی استیشن 5'], ['PS5', 'پی اس فایو']], $current);

        self::assertSame([], $result['errors']);
        self::assertSame(['in_other_group'], self::codes($result, 'warnings'));
        $warning = $result['warnings'][0];
        self::assertSame(1, $warning['group']);
        self::assertEqualsCanonicalizing(['ps5', 'پلی استیشن 5', 'پی اس فایو'], $warning['merged']);
    }

    public function testAShortTermWarns(): void
    {
        $result = $this->validator()->validate([['tv', 'تلویزیون']], []);
        self::assertSame(['short_term'], self::codes($result, 'warnings'));
        self::assertSame('tv', $result['warnings'][0]['term']);
    }

    public function testABlocklistedTermWarns(): void
    {
        $result = $this->validator(null, ['کالا'])->validate([['کالا', 'محصول']], []);
        self::assertSame(['blocklisted'], self::codes($result, 'warnings'));
    }

    public function testATermMatchingTooManyProductsWarns(): void
    {
        $counter = static fn (string $term): int => $term === 'phone' ? 101 : 3;
        $result = $this->validator($counter)->validate([['phone', 'گوشی']], []);

        self::assertSame(['many_matches'], self::codes($result, 'warnings'));
        self::assertSame(101, $result['warnings'][0]['matches']);
        self::assertSame(100, $result['warnings'][0]['limit']);
    }

    public function testNoCounterMeansNoMatchWarning(): void
    {
        self::assertSame([], $this->validator()->validate([['phone', 'گوشی']], [])['warnings']);
    }

    public function testWarningsOnlyConcernGroupsThatChanged(): void
    {
        // The stored group is short, blocklisted and in another group; saving a different group must stay quiet.
        $current = [['tv', 'تلویزیون'], ['tv', 'television']];
        $counted = [];
        $counter = static function (string $term) use (&$counted): int {
            $counted[] = $term;

            return 9999;
        };
        $proposal = [['tv', 'تلویزیون'], ['tv', 'television'], ['laptop', 'لپ تاپ']];
        $result = $this->validator($counter, ['tv'])->validate($proposal, $current);

        self::assertSame(['many_matches', 'many_matches'], self::codes($result, 'warnings'));
        self::assertSame(['laptop', 'لپ تاپ'], $counted, 'only the new group is counted');
    }

    public function testAnEditedGroupWarnsAgainAboutItsTerms(): void
    {
        $current = [['tv', 'تلویزیون']];
        $result = $this->validator()->validate([['tv', 'تلویزیون', 'تی وی']], $current);
        self::assertContains('short_term', self::codes($result, 'warnings'));
    }

    public function testCountingIsBounded(): void
    {
        $calls = 0;
        $counter = static function () use (&$calls): int {
            $calls++;

            return 0;
        };
        $groups = [];
        for ($i = 0; $i < 60; $i++) {
            $groups[] = ["alpha{$i}", "beta{$i}"];
        }
        $this->validator($counter)->validate($groups, []);

        self::assertSame(50, $calls);
    }

    public function testPhraseMatchesWhatTheSearchCompares(): void
    {
        self::assertSame('ps5', AliasValidator::phrase(' PS۵ '));
        self::assertSame('', AliasValidator::phrase("\u{200C}"));
    }
}
