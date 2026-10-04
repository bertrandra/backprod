<?php

declare(strict_types=1);

namespace App\Navigation\Service;

use App\Navigation\Domain\NavigationSetup;
use App\Navigation\Domain\NavigationSetupRepository;
use App\Staff\Domain\StaffIdentity;

/**
 * The console's side of the menu setup: read it, replace it.
 *
 * Replaced whole, never patched: the document is three short lists and
 * three flags, and a PUT that carried all of them is one that cannot
 * silently leave an audience as it was because a screen forgot to send it
 * — the same reasoning as the supplier's tax position.
 */
final class NavigationDesk
{
    public function __construct(
        private readonly NavigationSetupRepository $setups,
    ) {
    }

    public function show(): NavigationSetup
    {
        return $this->setups->load();
    }

    public function replace(StaffIdentity $staff, NavigationSetup $setup): NavigationSetup
    {
        $this->setups->save($setup);

        return $setup;
    }
}
