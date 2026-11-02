<?php

declare(strict_types=1);

namespace App\Tests;

use App\GoldenEval;
use App\GoldenLabeling;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The pure CSV logic of tools/eval/make_candidates.php and finish_golden.php. */
final class GoldenLabelingTest extends TestCase
{
    public function testQueriesComeFromTheGroupedExportInOrderWithoutEqualNormalizedDuplicates(): void
    {
        $csv = "normalized_q,searches,last_seen\nps4,50,2026-10-01\nپی‌اس ۴,40,x\nپی اس 4,30,x\nPS4,20,x\n"
            . "gta 5,10,x\n,5,x\nxbox,1,x\n";

        self::assertSame(['ps4', 'پی‌اس ۴', 'gta 5'], GoldenLabeling::readQueries($csv, 3), 'PS4 repeats ps4; n caps');
        self::assertSame(['ps4', 'پی‌اس ۴', 'gta 5', 'xbox'], GoldenLabeling::readQueries("\xEF\xBB\xBF" . $csv, 99));
    }

    public function testAFileWithoutTheGroupedColumnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normalized_q');
        GoldenLabeling::readQueries("ts,raw_q\n1,ps4\n", 10);
    }

    public function testCandidateRowsAreOnePerResultWithAPlaceholderForNoResults(): void
    {
        self::assertSame(
            [['ps4', '1', '9', 'PS4 Slim', '', ''], ['ps4', '2', '4', '', '', '']],
            GoldenLabeling::candidateRows('ps4', [9, 4], [9 => 'PS4 Slim'])
        );
        self::assertSame([['zzz', '', '', '(no results)', '', '']], GoldenLabeling::candidateRows('zzz', [], []));
    }

    public function testTheCandidateSheetIsUtf8WithABomAndKeepsPersianAndCommas(): void
    {
        $csv = GoldenLabeling::candidatesCsv(GoldenLabeling::candidateRows('کنسول, ps4', [1], [1 => 'بازی "x"']));

        self::assertStringStartsWith("\xEF\xBB\xBFquery,rank,product_id,title,relevant,note\n", $csv);
        $back = GoldenLabeling::finish(str_replace(',,', ',1,', $csv));
        self::assertSame('کنسول, ps4', $back['golden'][0]['query']);
    }

    private function sheet(string ...$lines): string
    {
        return "query,rank,product_id,title,relevant,note\n" . implode("\n", $lines) . "\n";
    }

    public function testFinishBuildsRelevantIdsOffTopicRowsAndListsTheSkipped(): void
    {
        $result = GoldenLabeling::finish($this->sheet(
            'ps4,1,10,A,1,two right',
            'ps4,2,11,B,0,',
            'ps4,3,12,C,1,',
            'shirt,1,20,T,0,off topic',
            'shirt,2,21,U,0,',
            'tablet,1,30,V,0,',
            'tablet,2,31,W,0,',
            'xbox,1,40,X,,',
            'gta,1,50,Y,0,',
            'gta,2,51,Z,,off',
            'offer,1,60,O,0,offer'
        ));

        self::assertSame([
            ['query' => 'ps4', 'relevant_ids' => '10;12', 'note' => 'two right'],
            ['query' => 'shirt', 'relevant_ids' => '', 'note' => 'off topic'],
        ], $result['golden']);
        self::assertSame([
            ['query' => 'tablet', 'reason' => 'all rows 0 but no "off" in a note column'],
            ['query' => 'xbox', 'reason' => 'no mark'],
            ['query' => 'gta', 'reason' => 'no 1 and some rows left unmarked'],
            ['query' => 'offer', 'reason' => 'all rows 0 but no "off" in a note column'],
        ], $result['skipped'], '"offer" is not the word off');
    }

    public function testAnyColumnStartingWithNoteCountsAndSemicolonExcelFilesAndPersianDigitsWork(): void
    {
        $csv = "\xEF\xBB\xBFquery;rank;product_id;title;relevant;note;note 2\n"
            . "ps4;1;10;A;۱;;fine\nclothes;1;;(no results);0;OFF;\n";
        $result = GoldenLabeling::finish($csv);

        self::assertSame(
            [['query' => 'ps4', 'relevant_ids' => '10', 'note' => 'fine'],
             ['query' => 'clothes', 'relevant_ids' => '', 'note' => 'OFF']],
            $result['golden']
        );
    }

    public function testABadMarkNamesTheRowInsteadOfBeingGuessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row 3 (ps4): relevant must be 1, 0 or empty, got "x"');
        GoldenLabeling::finish($this->sheet('ps4,1,10,A,1,', 'ps4,2,11,B,x,'));
    }

    public function testTheGoldenFileRoundTripsThroughTheFormatRunEvalReads(): void
    {
        $result = GoldenLabeling::finish($this->sheet('ps4,1,10,A,1,n1', 'ps4,2,11,B,1,', 'shirt,1,20,T,0,off'));
        $cases = GoldenEval::parseCsv(GoldenLabeling::goldenCsv($result['golden']));

        self::assertSame([10, 11], $cases[0]['relevant']);
        self::assertSame('shirt', $cases[1]['query']);
        self::assertSame([], $cases[1]['relevant']);
    }

    public function testRunsRecordTheirDepthAndAnOldRunUsedDepthEqualsK(): void
    {
        self::assertSame(300, GoldenEval::depthOf(['k' => 10, 'depth' => 300]));
        self::assertSame(10, GoldenEval::depthOf(['k' => 10]));
    }
}
