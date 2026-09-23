<?php

declare(strict_types=1);

namespace App\Tests;

use App\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract 1 (normalization parity), PHP side. Asserts Normalizer::normalize
 * matches the shared fixture that pipeline/normalize.py is verified against, so
 * both implementations agree character-for-character.
 */
final class NormalizerParityTest extends TestCase
{
    /**
     * @return array{
     *     normalization_version: int,
     *     cases: list<array{name: string, input: string, expected: string}>,
     *     sku_cases: list<array{name: string, input: string, expected: string}>
     * }
     */
    private static function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/normalization_cases.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testVersionMatchesFixture(): void
    {
        self::assertSame(self::fixture()['normalization_version'], Normalizer::VERSION);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function caseProvider(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['name'] => [$case['input'], $case['expected']];
        }
    }

    #[DataProvider('caseProvider')]
    public function testNormalizationMatchesExpected(string $input, string $expected): void
    {
        self::assertSame($expected, Normalizer::normalize($input));
    }

    #[DataProvider('caseProvider')]
    public function testNormalizationIsIdempotent(string $input, string $expected): void
    {
        self::assertSame($expected, Normalizer::normalize($expected));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function skuCaseProvider(): iterable
    {
        foreach (self::fixture()['sku_cases'] as $case) {
            yield $case['name'] => [$case['input'], $case['expected']];
        }
    }

    #[DataProvider('skuCaseProvider')]
    public function testSkuNormalizationMatchesExpected(string $input, string $expected): void
    {
        self::assertSame($expected, Normalizer::normalizeSku($input));
        self::assertSame($expected, Normalizer::normalizeSku($expected));
    }

    public function testNullNormalizesToEmptyString(): void
    {
        self::assertSame('', Normalizer::normalize(null));
    }
}
