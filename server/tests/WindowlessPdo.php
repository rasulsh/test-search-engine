<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO that refuses window functions the way a MariaDB older than 10.2 does
 * (a syntax error at prepare time), so tests never depend on the server version.
 */
final class WindowlessPdo extends PDO
{
    /** @var list<string> */
    public array $refused = [];

    public static function connect(): self
    {
        return new self(
            (string) getenv('SEARCH_TEST_DB_DSN'),
            getenv('SEARCH_TEST_DB_USER') ?: '',
            getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, ' OVER (')) {
            $this->refused[] = $query;

            throw new PDOException("SQLSTATE[42000]: Syntax error: 1064 near 'OVER (' (simulated)");
        }

        return parent::prepare($query, $options);
    }
}
