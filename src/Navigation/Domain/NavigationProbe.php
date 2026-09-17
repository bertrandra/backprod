<?php

declare(strict_types=1);

namespace App\Navigation\Domain;

/**
 * Which menu entries have nothing behind them, for one reader.
 *
 * Asked only for an audience whose setup switched `hideEmpty` on, and
 * answered only about the entries the implementation knows name a list of
 * the reader's own rows — an entry it does not know is never reported,
 * because "nothing to show" is a claim this must back with a count, not a
 * guess. A settings screen, a profile, a catalogue somebody else fills: none
 * of those is a list of the reader's rows, so none is ever hidden on this
 * ground.
 */
interface NavigationProbe
{
    /**
     * The tenant-side entries with no rows for this tenant and product.
     *
     * @return list<string> entry ids, as the shell names them
     */
    public function emptyForTenant(string $tenantId, string $productId): array;

    /**
     * The console entries with no rows anywhere on the platform.
     *
     * @return list<string>
     */
    public function emptyForPlatform(): array;
}
