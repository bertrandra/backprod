<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * How many people a subscription covers (§13.1).
 *
 * One rule, in the domain, because two places now need it: the service that
 * enforces the quota when somebody is added, and the read model behind the
 * organisation screen. A second copy would be a number a customer paid for,
 * computed twice.
 */
final class Places
{
    /**
     * The feature whose quota bounds a subscription's people. A platform
     * convention, not a product's — the same word everywhere, which is what
     * ADR-052 is about.
     */
    public const USERS_FEATURE = 'users';

    /**
     * What an offer sells, counting the holder.
     *
     * The two nulls are not the same null, which is the whole reason this is
     * a function rather than a column read:
     *
     * - **no grant at all** covers the holder alone. A subscription is one
     *   person's unless the offer says otherwise;
     * - **a grant with no limit** covers everybody.
     *
     * @param bool     $granted whether the offer grants the `users` feature
     * @param int|null $limit   the limit it carries, when it does
     *
     * @return int|null null for unlimited
     */
    public static function sold(bool $granted, ?int $limit): ?int
    {
        return $granted ? $limit : 1;
    }
}
