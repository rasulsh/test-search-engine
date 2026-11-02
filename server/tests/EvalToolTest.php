<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * tools/eval/run_eval.php end to end: the real CLI against a seeded database, on a golden file with the
 * sample's shape. An evaluation must not write search_logs.
 */
final class EvalToolTest extends DatabaseTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = TempTree::path('eval_tool');
        mkdir($this->dir, 0777, true);
        $this->loadSampleFixture();
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dir);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $extraConfig
     * @return array{0: int, 1: string} exit code and output
     */
    private function cli(array $args, array $extraConfig = [], string $script = 'run_eval'): array
    {
        file_put_contents($this->dir . '/config.php', '<?php return ' . var_export($extraConfig + [
            'db' => [
                'dsn'      => (string) getenv('SEARCH_TEST_DB_DSN'),
                'user'     => (string) getenv('SEARCH_TEST_DB_USER'),
                'password' => (string) getenv('SEARCH_TEST_DB_PASSWORD'),
            ],
            'paths' => ['data' => $this->dir . '/data'],
        ], true) . ';');
        $command = PHP_BINARY . ' ' . escapeshellarg(self::repoRoot() . "/tools/eval/{$script}.php") . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        exec('SEARCH_CONFIG_FILE=' . escapeshellarg($this->dir . '/config.php') . ' ' . $command, $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function testItRunsTheGoldenFileSavesTheRunAndWritesNoLogs(): void
    {
        file_put_contents(
            $this->dir . '/golden.csv',
            "# fake\nquery,relevant_ids,note\nsony,1009;1011,two sony products\nzzzzqx,,nothing\nsony camera,1011,\n"
        );

        [$code, $out] = $this->cli(['--golden=' . $this->dir . '/golden.csv', '--out-dir=' . $this->dir . '/runs']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('3 queries, k=10, depth=300, keyword_only', $out);
        self::assertStringContainsString('success@10 1.000', $out);
        $saved = glob($this->dir . '/runs/eval-*.json') ?: [];
        self::assertCount(1, $saved);
        $run = json_decode((string) file_get_contents($saved[0]), true);
        self::assertEquals(1.0, $run['success_at_k']);
        self::assertSame(300, $run['depth'], 'the storefront depth is the default');
        self::assertSame(10, $run['k']);
        self::assertSame(1, $run['per_query'][0]['first_rank']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn(), 'no log row');
    }

    public function testCompareModePrintsRegressions(): void
    {
        $row = static fn (int $rank): array => [
            'query' => 'q', 'note' => '', 'relevant' => 1, 'results' => 3, 'first_rank' => $rank,
            'success' => true, 'rr' => 0.0, 'ndcg' => 0.0, 'zero' => false,
        ];
        $run = static fn (int $rank): array => [
            'success_at_k' => 1.0, 'mrr' => 1.0, 'ndcg_at_k' => 1.0, 'zero_result_rate' => 0.0,
            'per_query' => [$row($rank)],
        ];
        file_put_contents($this->dir . '/a.json', json_encode($run(1)));
        file_put_contents($this->dir . '/b.json', json_encode($run(4)));

        [$code, $out] = $this->cli(['--compare', $this->dir . '/a.json', $this->dir . '/b.json']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('REGRESSED: 1', $out);
        self::assertMatchesRegularExpression('/q\s+rank 1 -> 4/', $out);
    }

    /** @return array{0: MockVpsServer, 1: array<string, mixed>} the depth fixture of SearchDepthTest, with a mock VPS */
    private function depthFixture(): array
    {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['product_id' => $i, 'title' => "PS4 Game {$i}", 'stock' => 5, 'popularity' => 500 - $i];
        }
        $rows[] = ['product_id' => 5000, 'title' => 'PS4 Game Just Cause', 'stock' => 5, 'popularity' => 1];
        (new \App\ProductLoader($this->pdo, 'products'))->load($rows);
        $vector = static fn (float $cosine): array => [$cosine, sqrt(1.0 - $cosine ** 2)];
        $products = [5000 => $vector(0.95)];
        for ($i = 1; $i <= 60; $i++) {
            $products[$i] = $vector(0.5);
        }
        $vps = new MockVpsServer(['products' => $products, 'queries' => ['ps4' => [1.0, 0.0]]]);

        return [$vps, ['vps' => ['url' => $vps->url, 'token' => FakeVps::TOKEN, 'timeout_ms' => 3000]]];
    }

    public function testTheEvaluationAsksForTheStorefrontDepthNotForK(): void
    {
        [$vps, $config] = $this->depthFixture();
        file_put_contents($this->dir . '/golden.csv', "query,relevant_ids,note\nps4,5000,the nearest neighbour\n");
        $base = ['--golden=' . $this->dir . '/golden.csv', '--no-save'];

        [$code, $deep] = $this->cli($base, $config);
        [, $shallow] = $this->cli([...$base, '--depth=10'], $config);

        self::assertSame(0, $code, $deep);
        self::assertStringContainsString('success@10 1.000', $deep, 'ranked at depth 300 it leads the first page');
        self::assertStringContainsString('success@10 0.000', $shallow, 'ranked at depth 10 it never reaches it');
        self::assertStringContainsString('depth=10', $shallow);
        unset($vps);
    }

    public function testDepthBelowKIsAUsageErrorAndCompareRefusesRunsOfDifferentDepth(): void
    {
        $sample = self::repoRoot() . '/tools/eval/golden.sample.csv';
        [$code, $out] = $this->cli(['--golden=' . $sample, '--k=10', '--depth=5']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--depth (5) must be at least --k (10)', $out);

        $run = static fn (int $depth): string => (string) json_encode([
            'k' => 10, 'depth' => $depth, 'success_at_k' => 1.0, 'mrr' => 1.0, 'ndcg_at_k' => 1.0,
            'zero_result_rate' => 0.0, 'per_query' => [],
        ]);
        file_put_contents($this->dir . '/d300.json', $run(300));
        file_put_contents($this->dir . '/d300b.json', $run(300));
        file_put_contents($this->dir . '/d10.json', $run(10));
        file_put_contents($this->dir . '/old.json', (string) json_encode(['k' => 10, 'per_query' => []]));

        [$code, $out] = $this->cli(['--compare', $this->dir . '/d300.json', $this->dir . '/d10.json']);
        self::assertSame(2, $code);
        self::assertStringContainsString('different depths (300 and 10)', $out);
        [$code] = $this->cli(['--compare', $this->dir . '/old.json', $this->dir . '/d300.json']);
        self::assertSame(2, $code, 'a run saved before --depth existed used depth = k');
        [$code] = $this->cli(['--compare', $this->dir . '/d300.json', $this->dir . '/d300b.json']);
        self::assertSame(0, $code);
    }

    public function testMakeCandidatesAndFinishGoldenTurnTheLogExportIntoAGoldenFile(): void
    {
        [$vps, $config] = $this->depthFixture();
        file_put_contents(
            $this->dir . '/top.csv',
            "normalized_q,searches,last_seen\nps4,50,2026-10-01\nPS4,40,2026-10-01\n"
            . "zzzzqx,30,2026-10-01\nsony,20,2026-10-01\n"
        );
        $args = ['--queries=' . $this->dir . '/top.csv', '--k=10', '--out=' . $this->dir . '/candidates.csv'];

        [$code, $out] = $this->cli([...$args, '--n=3'], $config, 'make_candidates');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('3 queries (k=10, depth=300)', $out);
        $sheet = (string) file_get_contents($this->dir . '/candidates.csv');
        self::assertStringStartsWith("\xEF\xBB\xBFquery,rank,product_id,title,relevant,note\n", $sheet);
        $lead = "ps4,1,5000,\"PS4 Game Just Cause\",,";
        self::assertStringContainsString($lead . "\n", $sheet, 'storefront depth: it leads');
        self::assertSame(1, substr_count($sheet, "\nps4,1,"), 'PS4 normalizes equal to ps4');
        self::assertStringContainsString('zzzzqx,,,"(no results)",,', $sheet);
        self::assertStringContainsString('sony,1,', $sheet, 'n=3 counts distinct queries, so sony is the third');

        [$code, $shallow] = $this->cli(
            ['--queries=' . $this->dir . '/top.csv', '--n=1', '--depth=10', '--out=' . $this->dir . '/shallow.csv'],
            $config,
            'make_candidates'
        );
        self::assertSame(0, $code, $shallow);
        self::assertStringContainsString('ps4,1,1,', (string) file_get_contents($this->dir . '/shallow.csv'));
        self::assertStringNotContainsString('5000', (string) file_get_contents($this->dir . '/shallow.csv'));
        unset($vps);

        // The Excel step: mark the sheet, then finish.
        $marked = str_replace(
            [$lead, 'zzzzqx,,,"(no results)",,'],
            ['ps4,1,5000,"PS4 Game Just Cause",1,', 'zzzzqx,,,"(no results)",0,off'],
            $sheet
        );
        file_put_contents($this->dir . '/marked.csv', $marked);
        $golden = $this->dir . '/golden.csv';
        $finish = ['--in=' . $this->dir . '/marked.csv', '--out=' . $golden];

        [$code, $out] = $this->cli($finish, [], 'finish_golden');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('wrote 2 queries', $out);
        self::assertStringContainsString('skipped: sony (no mark)', $out);
        self::assertSame("query,relevant_ids,note\nps4,5000,\nzzzzqx,,off\n", file_get_contents($golden));

        [$code, $out] = $this->cli($finish, [], 'finish_golden');
        self::assertSame(2, $code, 'an existing golden file is not overwritten');
        self::assertStringContainsString('--force', $out);
        [$code] = $this->cli([...$finish, '--force'], [], 'finish_golden');
        self::assertSame(0, $code);

        [$code, $out] = $this->cli(['--golden=' . $golden, '--no-save'], [], 'run_eval');
        self::assertSame(0, $code, 'run_eval reads the file unchanged: ' . $out);
        self::assertStringContainsString('2 queries', $out);
    }

    public function testAMissingGoldenFileIsAUsageError(): void
    {
        [$code, $out] = $this->cli(['--golden=' . $this->dir . '/nope.csv']);

        self::assertSame(2, $code);
        self::assertStringContainsString('golden file not found', $out);
    }

    public function testTheSampleFileRunsEndToEnd(): void
    {
        [$code, $out] = $this->cli([
            '--golden=' . self::repoRoot() . '/tools/eval/golden.sample.csv',
            '--no-save',
        ]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('4 queries', $out);
        self::assertStringContainsString('zero-result rate', $out);
    }
}
