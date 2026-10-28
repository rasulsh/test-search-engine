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

    /** @param list<string> $args @return array{0: int, 1: string} exit code and output */
    private function cli(array $args): array
    {
        file_put_contents($this->dir . '/config.php', '<?php return ' . var_export([
            'db' => [
                'dsn'      => (string) getenv('SEARCH_TEST_DB_DSN'),
                'user'     => (string) getenv('SEARCH_TEST_DB_USER'),
                'password' => (string) getenv('SEARCH_TEST_DB_PASSWORD'),
            ],
            'paths' => ['data' => $this->dir . '/data'],
        ], true) . ';');
        $command = PHP_BINARY . ' ' . escapeshellarg(self::repoRoot() . '/tools/eval/run_eval.php') . ' '
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
        self::assertStringContainsString('3 queries, k=10, keyword_only', $out);
        self::assertStringContainsString('success@10 1.000', $out);
        $saved = glob($this->dir . '/runs/eval-*.json') ?: [];
        self::assertCount(1, $saved);
        $run = json_decode((string) file_get_contents($saved[0]), true);
        self::assertEquals(1.0, $run['success_at_k']);
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
