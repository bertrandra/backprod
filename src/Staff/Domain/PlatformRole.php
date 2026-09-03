<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * The roles that act across the tenant boundary (§25.2, §12.2).
 *
 * Named here so a typo is a constant that does not exist rather than a
 * string that silently matches nothing — which, for an authorisation check,
 * fails in the safe direction but hides the mistake.
 *
 * These are deliberately *not* the tenant roles. TENANT_ADMIN is the
 * customer's administrator and appears nowhere in this list; nothing in the
 * platform maps one onto the other.
 */
final class PlatformRole
{
    public const PLATFORM_ADMIN = 'PLATFORM_ADMIN';
    public const SUPPORT_ADMIN = 'SUPPORT_ADMIN';
    public const FINANCE_ADMIN = 'FINANCE_ADMIN';
    public const SALES_ADMIN = 'SALES_ADMIN';
}
