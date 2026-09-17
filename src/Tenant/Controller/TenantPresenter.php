<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Tenant\Domain\Tenant;

/**
 * One shape for a tenant, so the read and the update endpoints cannot drift
 * into returning different representations of the same thing.
 *
 * The shape is the contract's: `{tenant: Tenant}`, with `may_author_offers`
 * on it. Until 2026-09-17 the two endpoints answered the bare tenant and
 * without that flag, and nothing failed — no test asserted the envelope,
 * the generated client typed what the contract promised, and the screen
 * that read `data.tenant` got `undefined` and showed the tenant's id where
 * its name should have been. The operator noticed the id. The contract
 * was right; this now says what it says.
 */
final class TenantPresenter
{
    /**
     * @param array{policy: string, domains: list<string>} $joining how people arrive by themselves (2026-09-17)
     *
     * @return array{tenant: array{id: string, name: string, slug: string, may_author_offers: bool, join_policy: string, join_domains: list<string>}}
     */
    public static function one(Tenant $tenant, array $joining): array
    {
        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'may_author_offers' => $tenant->mayAuthorOffers,
                'join_policy' => $joining['policy'],
                'join_domains' => $joining['domains'],
            ],
        ];
    }
}
