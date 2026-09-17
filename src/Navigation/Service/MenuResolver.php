<?php

declare(strict_types=1);

namespace App\Navigation\Service;

use App\Navigation\Domain\Audience;
use App\Navigation\Domain\NavigationProbe;
use App\Navigation\Domain\NavigationSetupRepository;

/**
 * What one reader's menu leaves out — the setup for their audience, plus,
 * where that audience asked for it, whatever has nothing behind it.
 *
 * Answered as one list of entry ids to hide, already resolved, so the shell
 * has one rule to apply beside the permission one and no reason to know
 * why an entry is absent. Hiding is courtesy, as it is for permissions
 * (ui-spec §"Gating is data"): the API refuses on permissions regardless,
 * and an entry hidden here is one the person could still reach by address
 * and be answered.
 */
final class MenuResolver
{
    public function __construct(
        private readonly NavigationSetupRepository $setups,
        private readonly NavigationProbe $probe,
    ) {
    }

    /**
     * @param list<string> $roles the membership's roles
     *
     * @return list<string> entry ids to leave out
     */
    public function forMember(array $roles, string $tenantId, string $productId): array
    {
        $menu = $this->setups->load()->for(Audience::ofMember($roles));

        return self::merge(
            $menu->hidden,
            $menu->hideEmpty ? $this->probe->emptyForTenant($tenantId, $productId) : [],
        );
    }

    /** @return list<string> */
    public function forStaff(): array
    {
        $menu = $this->setups->load()->for(Audience::PLATFORM_ADMIN);

        return self::merge($menu->hidden, $menu->hideEmpty ? $this->probe->emptyForPlatform() : []);
    }

    /**
     * @param list<string> $hidden
     * @param list<string> $empty
     *
     * @return list<string>
     */
    private static function merge(array $hidden, array $empty): array
    {
        $ids = array_values(array_unique([...$hidden, ...$empty]));
        sort($ids);

        return $ids;
    }
}
