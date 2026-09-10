<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\DirectoryPage;
use App\Shared\Http\PageRequest;

/**
 * The bounds and the envelope the five operational listings share.
 *
 * A helper rather than a base class, because every controller in this
 * platform is final and holds its own dependency — the shape `AdminRoute` and
 * `StaffRoute` already use. What is shared here is a page limit and a
 * response shape, and five copies of those would be five places for one of
 * them to drift.
 */
final class DirectoryListing
{
    /** Generous for an operator, small enough that a page is a screen's work. */
    public const MAX_PAGE = 200;

    public const MAX_OFFSET = 100_000;

    /**
     * @param array<array-key, mixed> $query
     */
    public static function limit(array $query): int
    {
        return PageRequest::bounded($query, 'limit', 50, 1, self::MAX_PAGE);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public static function offset(array $query): int
    {
        return PageRequest::bounded($query, 'offset', 0, 0, self::MAX_OFFSET);
    }

    /**
     * @param DirectoryPage<array<string, mixed>> $page
     *
     * @return array<string, mixed>
     */
    public static function envelope(string $key, DirectoryPage $page): array
    {
        return [
            $key => $page->rows,
            'total' => $page->total,
            'limit' => $page->limit,
            'offset' => $page->offset,
        ];
    }
}
