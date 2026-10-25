<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * How many products a term matches by itself (M41): the keyword tier with no synonym or alias
 * expansion, counting only hits that contain every word. Computed on demand for one term or one
 * group, never for the whole list on page load. The count stops just above the cap, so a very
 * common word costs one bounded query.
 */
final class AliasCounts
{
    public function __construct(private readonly Keyword $keyword, private readonly int $cap)
    {
    }

    /** @param array<string, mixed> $config the server config array */
    public static function fromConfig(PDO $pdo, array $config): self
    {
        return new self(
            SearchController::keywordFromConfig($pdo, $config, null),
            (int) $config['admin']['alias_warn_matches']
        );
    }

    /** Products containing every word of $term; at most cap + 1. */
    public function count(string $term): int
    {
        $hits = $this->keyword->search($term, $this->cap + 1, true);

        return count(array_filter($hits, static fn (array $hit): bool => $hit['coverage'] >= 1.0));
    }

    public function cap(): int
    {
        return $this->cap;
    }
}
