<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class ForbiddenException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $code, string $message, array $details = [])
    {
        parent::__construct(403, $code, $message, $details);
    }

    public static function noTenantAccess(): self
    {
        return new self(
            'NO_TENANT_ACCESS',
            'This account has no access to the requested product.',
        );
    }

    /**
     * The caller's role does not allow this. Distinct from an entitlement
     * failure: this is about who they are in the tenant, not what the tenant
     * has bought, and the two are fixed in completely different places.
     */
    public static function permissionDenied(string $permission): self
    {
        return new self(
            'PERMISSION_DENIED',
            'Your role does not allow this action.',
            ['permission' => $permission],
        );
    }

    /**
     * The capability name is safe to return: it tells the caller what to buy
     * or request, and discloses nothing about other tenants.
     */
    public static function entitlementRequired(string $capability): self
    {
        return new self(
            'ENTITLEMENT_REQUIRED',
            'This feature is not enabled for the tenant.',
            ['capability' => $capability],
        );
    }
}
