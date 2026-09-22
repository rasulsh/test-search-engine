<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Validates SQL identifiers (table/column names) that come from config and thus
 * cannot be passed as bound parameters. Config is trusted, but validating keeps
 * a typo or a misconfigured value from producing broken or unsafe SQL.
 */
final class Identifier
{
    public static function quote(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$name}");
        }

        return "`{$name}`";
    }
}
