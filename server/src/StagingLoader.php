<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Loads a bundle's products.load.sql into the staging table from PHP, so a
 * release needs no mysql client or phpMyAdmin import (M12).
 *
 * The file is streamed line by line and split into statements at a line that
 * ends with ";" outside a string literal, so memory stays at one statement
 * (build.py caps them at SEARCH_LOAD_MAX_STATEMENT_BYTES). Only the statement
 * shapes build.py emits, all aimed at the staging table, are executed: the
 * live table can never be touched by a crafted or wrong file.
 */
final class StagingLoader
{
    public const LOAD_FILE = 'products.load.sql';

    private PDO $pdo;
    private string $staging;

    public function __construct(PDO $pdo, string $stagingTable)
    {
        $this->pdo = $pdo;
        $this->staging = $stagingTable;
        Identifier::quote($stagingTable); // validate early
    }

    /**
     * @return int statements executed
     * @throws ReloadException when the file is missing, has an unexpected
     *         statement, or a statement fails. The staging table may then hold a
     *         partial load; the caller must not swap it in.
     */
    public function load(string $path): int
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new ReloadException('missing_load_sql', ['path' => $path]);
        }

        $executed = 0;
        try {
            $statement = '';
            $inString = false;
            while (($line = fgets($handle)) !== false) {
                if ($statement === '' && !$inString && $this->isSkippable($line)) {
                    continue;
                }
                $statement .= $line;
                $inString = $this->endsInString($line, $inString);
                if (!$inString && str_ends_with(rtrim($line), ';')) {
                    $this->execute($statement, $executed + 1);
                    $executed++;
                    $statement = '';
                }
            }
            if (trim($statement) !== '') {
                throw new ReloadException('staging_load_failed', [
                    'statement' => $executed + 1,
                    'error' => 'unterminated statement at end of file',
                ]);
            }
        } finally {
            fclose($handle);
        }

        return $executed;
    }

    private function isSkippable(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed === '' || str_starts_with($trimmed, '--');
    }

    /**
     * Track whether a string literal is still open after $line. Backslash
     * escapes are skipped; a doubled quote toggles twice, so it nets out.
     */
    private function endsInString(string $line, bool $inString): bool
    {
        preg_match_all("/\\\\.|'/s", $line, $matches);
        foreach ($matches[0] as $token) {
            if ($token === "'") {
                $inString = !$inString;
            }
        }

        return $inString;
    }

    private function execute(string $sql, int $number): void
    {
        if (!$this->isAllowed($sql)) {
            throw new ReloadException('unexpected_statement', [
                'statement' => $number,
                'start' => mb_substr(ltrim($sql), 0, 80),
            ]);
        }
        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            throw new ReloadException('staging_load_failed', [
                'statement' => $number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isAllowed(string $sql): bool
    {
        $table = preg_quote($this->staging, '/');
        $patterns = [
            '/^SET NAMES utf8mb4;$/i',
            "/^DROP TABLE IF EXISTS `{$table}`;$/i",
            "/^CREATE TABLE `{$table}` \\(/i",
            "/^REPLACE INTO `{$table}`\\s/i",
        ];
        $sql = trim($sql);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
