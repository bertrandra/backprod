<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * One page of an operational listing, and how much there is behind it.
 *
 * The total is counted rather than inferred from a short page, because an
 * operator paging through customers needs to know whether they are looking at
 * forty or at four thousand — and "the page came back short" answers that
 * only on the last page.
 *
 * @template T
 */
final class DirectoryPage
{
    /**
     * @param list<T> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
