<?php

declare(strict_types=1);

namespace App;

/**
 * The one definition of "a search" for the analytics: a log row that is not a repeat submit, is the
 * first page and was not a storefront reload (sort / limit / warehouse, source 'filter'), and is not
 * part of a burst. The raw log list is not filtered by it. A condition whose column the table lacks
 * (an install from before that migration) is left out, which is the old behaviour.
 */
final class SearchRows
{
    /** search_logs.source of a storefront reload of the same search (sort, limit, warehouse). */
    public const SOURCE_FILTER = 'filter';

    /**
     * @param callable(string): bool $hasColumn
     * @return list<string> SQL conditions, to be ANDed
     */
    public static function conditions(callable $hasColumn, string $alias = ''): array
    {
        $conditions = [];
        if ($hasColumn('is_duplicate')) {
            $conditions[] = "{$alias}is_duplicate = 0";
        }
        if ($hasColumn('page')) {
            $conditions[] = "{$alias}page = 1";
        }
        if ($hasColumn('source')) {
            $conditions[] = "{$alias}source <> '" . self::SOURCE_FILTER . "'";
        }
        if ($hasColumn('is_burst')) {
            $conditions[] = "{$alias}is_burst = 0";
        }

        return $conditions;
    }
}
