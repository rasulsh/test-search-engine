<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminAliasViews;
use PHPUnit\Framework\TestCase;

/** M44: the tester page shows the brands / platforms the query named and the brand asked for and not found. */
final class AdminTesterEntitiesTest extends TestCase
{
    /** @param array<string, mixed>|null $entities */
    private function page(?array $entities): string
    {
        return AdminAliasViews::tester([
            'q' => 'مانیتور شیامی',
            'titles' => [1 => 'مانیتور ایسوس'],
            'result' => [
                'query' => ['raw' => 'مانیتور شیامی', 'normalized' => 'مانیتور شیامی'],
                'did_you_mean' => null, 'did_you_mean_applied' => false, 'count' => 1, 'product_ids' => [1],
                'debug' => [
                    'tier' => 'keyword_only',
                    'entities' => $entities,
                    'results' => [[
                        'product_id' => 1, 'rank' => 1, 'pinned' => false, 'blend' => null,
                        'keyword_hit' => ['match_type' => 'title', 'score' => 1.0, 'title_match' => true,
                            'field_match' => false, 'name_all' => true, 'coverage' => 0.5],
                    ]],
                ],
            ],
        ]);
    }

    public function testTheBrandAskedForAndNotFoundIsListed(): void
    {
        $html = $this->page(['brands' => ['xiaomi'], 'platforms' => ['ps4'], 'active' => ['brand' => false, 'platform' => false],
            'brand_unmatched' => ['xiaomi']]);

        self::assertStringContainsString('برند و پلتفرم شناخته‌شده', $html);
        self::assertStringContainsString('xiaomi، ps4', $html);
        self::assertStringContainsString('برندِ خواسته‌شده که در نتایج نیست', $html);
        self::assertStringContainsString('کلمهٔ کامل در عنوان', $html, 'the new match type has a Persian label');
    }

    public function testNothingIsShownWhenTheQueryNamedNoEntity(): void
    {
        $html = $this->page(null);

        self::assertStringNotContainsString('برند و پلتفرم شناخته‌شده', $html);
        self::assertStringNotContainsString('برندِ خواسته‌شده که در نتایج نیست', $html);
    }

    public function testAMatchedBrandListsNoUnmatchedRow(): void
    {
        $html = $this->page(['brands' => ['asus'], 'platforms' => [], 'active' => ['brand' => true, 'platform' => false],
            'brand_unmatched' => []]);

        self::assertStringContainsString('برند و پلتفرم شناخته‌شده', $html);
        self::assertStringNotContainsString('برندِ خواسته‌شده که در نتایج نیست', $html);
    }
}
