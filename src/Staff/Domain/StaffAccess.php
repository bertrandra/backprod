<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * One crossing of the tenant boundary by platform staff.
 *
 * Non-negotiable #21 says such an access is traced, motivated and never
 * silent, so this records not only who looked at what but *under which
 * permission* — the answer to "on what grounds?" that an access log without
 * it can never give.
 */
final class StaffAccess
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $staffUserId,
        public readonly ?string $tenantId,
        public readonly ?string $productId,
        public readonly string $action,
        public readonly string $resourceType,
        public readonly ?string $resourceId,
        public readonly string $permission,
        public readonly array $detail = [],
    ) {
    }
}
