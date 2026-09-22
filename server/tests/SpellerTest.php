<?php

declare(strict_types=1);

namespace App\Tests;

use App\Speller;
use PHPUnit\Framework\TestCase;

final class SpellerTest extends TestCase
{
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
}
