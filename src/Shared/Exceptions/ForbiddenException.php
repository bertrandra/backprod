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
