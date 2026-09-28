<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

/**
 * The window a list is narrowed to — one rule, written once (2026-09-28).
 *
 * The companion of {@see DocumentPersonSql}: that one answers *whose* a
 * document is, this one answers *when* it was. Both are shared rather than
 * repeated, so a list's page and its total cannot come to read different
 * WHERE clauses — the failure that is invisible until a customer counts.
 *
 * **The column is the one the list is ordered by**, and that is deliberate:
 * an invoice list sorted by the day it was issued and filtered by the day it
 * was created would answer a different question from the one on screen, and
 * the reader has no way to see which. So invoices use `coalesce(issued_at,
 * created_at)`, credit notes `issued_at`, payments and orders `created_at`,
 * and a subscription the day it started.
 *
 * **`to` is inclusive of its own day.** A window read as `< :to` drops
 * everything raised on the last day the reader asked for, which is the
 * off-by-one everybody writes once: asked for the 1st to the 31st, they are
 * given the 30th. So the bound is the midnight *after* `:to`.
 *
 * **Midnight is UTC**, stated rather than inherited. A bare `date` compared
 * with a `timestamptz` is resolved in the session's timezone, so the same
 * request would select different rows depending on what the database server
 * was set to — and the test database here is not on the same clock as the
 * application. Every stored moment is UTC; so is the window that selects it.
 */
final class DocumentWindowSql
{
    /**
     * @param string $column the moment expression this list is ordered by
     */
    public static function narrowing(string $column): string
    {
        return sprintf(
            "(CAST(:from AS date) IS NULL OR %1\$s >= CAST(:from AS date) AT TIME ZONE 'UTC')"
            . " AND (CAST(:to AS date) IS NULL OR %1\$s < (CAST(:to AS date) + 1) AT TIME ZONE 'UTC')",
            $column,
        );
    }
}
