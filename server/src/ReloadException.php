<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * A bundle that failed validation. Carries a machine-readable reason so the
 * endpoint can report which check rejected the reload. No swap has happened when
 * this is thrown.
 */
final class ReloadException extends RuntimeException
{
    private string $reason;
    /** @var array<string, mixed> */
    private array $details;

    /** @param array<string, mixed> $details */
    public function __construct(string $reason, array $details = [])
    {
        parent::__construct($reason);
        $this->reason = $reason;
        $this->details = $details;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
