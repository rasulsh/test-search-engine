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

    public function testDebugBreakdownIsOptInAndNeverBuiltFromMarkup(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__) . '/public/test.html');

        // Needs ?debug in the URL and a typed token, which goes in a header, never the URL or body.
        self::assertStringContainsString("searchParams.has('debug')", $html);
        self::assertStringContainsString("headers['X-Debug-Token'] = debugToken.value", $html);
        self::assertStringContainsString('body.debug = 1', $html);
        self::assertStringContainsString('data.debug?.results', $html);
        // The server's text is shown as text.
        self::assertStringNotContainsString('innerHTML', $html);
        // The breakdown fields the server sends (see SearchController::explain()).
        foreach (['keyword_hit', 'blend.boosts', 'blend.semantic', 'blend.keyword', 'row.pinned'] as $field) {
            self::assertStringContainsString($field, $html);
        }
    }
}
