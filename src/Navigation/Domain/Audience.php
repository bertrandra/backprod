<?php

declare(strict_types=1);

namespace App\Navigation\Domain;

/**
 * Who a menu is set up for.
 *
 * Three, and they are the three kinds of person this platform seats: the
 * platform's own administrator in the console, the customer's administrator
 * in their organisation, and a member of that organisation. A person is one
 * of these *per screen* — somebody who holds a platform role and a
 * membership is `PLATFORM_ADMIN` on `/console/*` and whichever their
 * membership says elsewhere — which is why the shell asks twice, once per
 * authority, exactly as it does for permissions (ADR-046).
 */
final class Audience
{
    public const PLATFORM_ADMIN = 'platform_admin';
    public const TENANT_ADMIN = 'tenant_admin';
    public const USER = 'user';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PLATFORM_ADMIN, self::TENANT_ADMIN, self::USER];
    }

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    /**
     * The tenant-side audience a membership's roles put somebody in. The
     * customer's administrator is `TENANT_ADMIN` on the membership (§12.2);
     * everybody else in the organisation is a user.
     *
     * @param list<string> $roles
     */
    public static function ofMember(array $roles): string
    {
        return in_array('TENANT_ADMIN', $roles, true) ? self::TENANT_ADMIN : self::USER;
    }
}
