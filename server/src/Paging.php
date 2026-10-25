<?php

declare(strict_types=1);

namespace App;

/**
 * Page arithmetic shared by the panel's paginated tables (aliases, logs, audit): which page is
 * shown, what to skip, and which page numbers get a link. Pure functions, no I/O.
 */
final class Paging
{
    /** The rows-per-page choices; a configured default outside the list is offered too. */
    public const SIZES = [25, 50, 100, 200];

    /**
     * A requested page size, or $default when it is not one of the choices (never an arbitrary
     * number from the query string).
     */
    public static function size(mixed $raw, int $default): int
    {
        $size = is_scalar($raw) && preg_match('/^\d{1,4}$/', (string) $raw) === 1 ? (int) $raw : 0;

        return in_array($size, self::sizes($default), true) ? $size : $default;
    }

    /** @return list<int> the choices, with the default added when it is not one of them */
    public static function sizes(int $default): array
    {
        $sizes = self::SIZES;
        if (!in_array($default, $sizes, true)) {
            $sizes[] = $default;
            sort($sizes);
        }

        return $sizes;
    }

    /**
     * @return array{page: int, pages: int, offset: int, limit: int, from: int, to: int, total: int}
     *         page is clamped to 1..pages (an empty list has one empty page); from / to are the
     *         1-based first and last row shown, both 0 when there is nothing to show
     */
    public static function of(int $total, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $total = max(0, $total);
        $pages = max(1, intdiv($total + $perPage - 1, $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'pages' => $pages,
            'offset' => $offset,
            'limit' => $perPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
            'total' => $total,
        ];
    }

    /**
     * The page numbers to link: the first and last, and a window around the current page; null marks
     * a gap ("...").
     *
     * @return list<int|null>
     */
    public static function window(int $page, int $pages, int $radius = 2): array
    {
        $shown = [1, $pages];
        for ($p = $page - $radius; $p <= $page + $radius; $p++) {
            $shown[] = $p;
        }
        $shown = array_values(array_unique(array_filter($shown, static fn (int $p): bool => $p >= 1 && $p <= $pages)));
        sort($shown);
        $out = [];
        foreach ($shown as $i => $p) {
            if ($i > 0 && $p - $shown[$i - 1] > 1) {
                $out[] = null;
            }
            $out[] = $p;
        }

        return $out;
    }
}
