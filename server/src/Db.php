<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Thin PDO wrapper. The only place a database connection is created, so PDO
 * attributes (exceptions, real prepares, associative fetch) are set once.
 */
final class Db
{
    private PDO $pdo;

    /**
     * @param array{dsn: string, user?: string, password?: string} $config
     */
    public function __construct(array $config)
    {
        $this->pdo = new PDO(
            $config['dsn'],
            $config['user'] ?? '',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Cheap connectivity check for /health. */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
