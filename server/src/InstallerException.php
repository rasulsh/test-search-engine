<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * A first-deploy step that stopped the installer before config.php was written
 * (or because it already exists). Carries a machine-readable reason and, for
 * invalid input, the failing fields.
 */
final class InstallerException extends RuntimeException
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
