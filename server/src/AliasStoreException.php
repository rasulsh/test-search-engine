<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** An alias file operation that did not happen; `reason()` says why (stale, busy, unknown_backup, io). */
final class AliasStoreException extends RuntimeException
{
    public function __construct(private readonly string $reason, string $detail = '')
    {
        parent::__construct($reason . ($detail === '' ? '' : ': ' . $detail));
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
