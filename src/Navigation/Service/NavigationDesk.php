<?php

declare(strict_types=1);

namespace App\Navigation\Service;

use App\Navigation\Domain\NavigationSetup;
use App\Navigation\Domain\NavigationSetupRepository;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

/**
 * The console's side of the menu setup: read it, replace it.
 *
 * Replaced whole, never patched: the document is three short lists and
 * three flags, and a PUT that carried all of them is one that cannot
 * silently leave an audience as it was because a screen forgot to send it
 * — the same reasoning as the supplier's tax position.
 *
 * Every replacement is recorded with the document it wrote. What the
 * platform shows each kind of person is a decision somebody should be able
 * to trace, and "who hid Invoices from every customer, and when" is a
 * question the access log answers.
 */
final class NavigationDesk
{
    public function __construct(
        private readonly NavigationSetupRepository $setups,
        private readonly StaffAccessLog $trail,
    ) {
    }

    public function show(): NavigationSetup
    {
        return $this->setups->load();
    }

    public function replace(StaffIdentity $staff, NavigationSetup $setup): NavigationSetup
    {
        $this->setups->save($setup);

        $this->trail->record(new StaffAccess(
            $staff->userId,
            // Platform-wide: no tenant and no product, because the decision
            // is about neither.
            null,
            null,
            'CONFIGURE_NAVIGATION',
            'platform_settings',
            'navigation',
            StaffPermission::NAVIGATION_MANAGE,
            $setup->toArray(),
        ));

        return $setup;
    }
}
