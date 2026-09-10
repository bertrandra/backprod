<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use DateTimeImmutable;

/**
 * One recorded crossing, as read back.
 *
 * Separate from {@see StaffAccess}, which is what a caller submits: this
 * carries the id and the moment the database assigned, and those are facts
 * about the record rather than about the act.
 */
final class StaffAccessEntry
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $id,
        public readonly string $staffUserId,
        public readonly ?string $tenantId,
        public readonly ?string $productId,
        public readonly string $action,
        public readonly string $resourceType,
        public readonly ?string $resourceId,
        public readonly string $permission,
        public readonly array $detail,
        public readonly DateTimeImmutable $occurredAt,
        /**
         * Why the read happened, as the person gave it (R14).
         *
         * Null on every row written before R14, and on the reads that cross no
         * boundary — listing a queue, reading this log. Null means "not
         * recorded", never "no reason".
         */
        public readonly ?string $purpose = null,
        public readonly ?string $reason = null,
    ) {
    }
}
