<?php

declare(strict_types=1);

namespace App\Tests;

use App\Speller;
use PHPUnit\Framework\TestCase;

/**
 * M44: "redeption2" is the word "redeption" and the number 2. The word is corrected, the number is
 * re-attached, and a suggestion is never allowed to lose a number the shopper typed.
 */
final class SpellerLetterDigitTest extends TestCase
{
    private function speller(): Speller
    {
        return new Speller(Speller::buildVocabulary([
            'red dead redemption 2 ps4',
            'red dead redemption ps4',
            'fc 27 ea sports',
            'ps4 console',
            'gta 5',
            'battlefield 4',
        ]));
    }

    public function testTheWordIsCorrectedAndTheNumberStays(): void
    {
        self::assertSame('redemption2', $this->speller()->suggest('redeption2'));
        $speller = new Speller(Speller::buildVocabulary(['بازی ps4 red dead redemption']));

        self::assertSame('بازی ps4 red dead redemption2', $speller->suggest('بازی ps4 red dead redeption2'));
    }

    public function testKnownAndTooShortModelNamesAreUntouched(): void
    {
        self::assertNull($this->speller()->suggest('ps4'));
        self::assertNull($this->speller()->suggest('fc27'), 'fc is too short to correct');
        self::assertNull($this->speller()->suggest('redemption2'), 'a known word with a number is left as typed');
    }

    public function testAWordWithItsNumberIsCorrectedWithoutTouchingTheOthers(): void
    {
        self::assertSame('battlefield4', $this->speller()->suggest('battelfield4'));
        self::assertSame('red dead redemption 2', $this->speller()->suggest('red dead redeption 2'));
    }

    public function testASuggestionThatWouldLoseADigitIsRejected(): void
    {
        $speller = new Speller(Speller::buildVocabulary(['grandtheft', 'grandtheft']));

        // One edit from "grandtheft" by dropping the stray digit, which the shopper typed on purpose.
        self::assertNull($speller->suggest('grand7theft'));
        self::assertSame('grandtheft', $speller->suggest('grandtheeft'), 'a letter typo is still corrected');
        self::assertNull($speller->suggest('grandtheft7'), 'a known word with a number is left as typed');
    }
}
