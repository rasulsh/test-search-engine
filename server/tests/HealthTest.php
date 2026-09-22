<?php

declare(strict_types=1);

namespace App\Tests;

use App\Db;
use App\Health;

final class HealthTest extends DatabaseTestCase
{
    private function db(): Db
    {
        return new Db([
            'dsn'      => getenv('SEARCH_TEST_DB_DSN') ?: '',
            'user'     => getenv('SEARCH_TEST_DB_USER') ?: '',
            'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
        ]);
    }

    public function testReportsOkAndProductCountWhenSeeded(): void
    {
        $this->loadSampleFixture();

        $result = (new Health($this->db(), 'products'))->check();

        self::assertSame('ok', $result['status']);
        self::assertTrue($result['checks']['database']);
        self::assertSame(14, $result['checks']['product_count']);
    }

    public function testReportsDegradedWhenTableMissing(): void
    {
        $result = (new Health($this->db(), 'nonexistent_table'))->check();

        self::assertSame('degraded', $result['status']);
        self::assertFalse($result['checks']['database']);
        self::assertNull($result['checks']['product_count']);
    }
}
