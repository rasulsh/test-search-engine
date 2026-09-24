<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * M18: the search test page runs no model. It sends only the query to
 * search.php and shows the cosine scores the server returns.
 */
final class TestPageTest extends TestCase
{
    public function testPageLoadsNoModelAndSendsNoQueryVector(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__) . '/public/test.html');

        foreach (['q_vector', 'client/', 'transformers', 'embedder', '.onnx', 'import(', '<script src'] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }
        self::assertStringContainsString("const body = { q, limit: LIMIT, with_details: true };", $html);
        self::assertStringContainsString('data.cosine_scores', $html);
    }
}
