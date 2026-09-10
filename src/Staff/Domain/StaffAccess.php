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
 *
 * **And, since R14, why** — the `motive` the person gave at the point of the
 * read. The permission is the *authority* for it; the motive is the reason. The
 * two were confused for a whole milestone, and U8 shipped a console that said
 * "this read is recorded under `staff.tenants.read`" because that was all the
 * platform knew.
 *
 * `motive` is null for the reads that cross no boundary — listing a queue,
 * reading this log — and for every row written before R14. Inventing one for
 * those would be forging an audit record.
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
        public readonly ?AccessMotive $motive = null,
    ) {
    }
}
