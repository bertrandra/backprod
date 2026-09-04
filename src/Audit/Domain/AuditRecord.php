<?php

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * What a caller hands the trail: what happened, and what it happened to.
 *
 * Separate from {@see AuditEntry}, which is what comes back out. An entry has
 * an id and a moment the database decided; a record has neither, and letting
 * a caller supply them would let a caller date an event.
 */
final class AuditRecord
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        public readonly string $action,
        public readonly string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $tenantId,
        public readonly ?string $productId,
        public readonly ?string $userId,
        public readonly ?string $projectId,
        public readonly ?string $requestId,
        public readonly array $detail,
    ) {
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function of(
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        ?string $tenantId = null,
        ?string $productId = null,
        ?string $userId = null,
        ?string $projectId = null,
        ?string $requestId = null,
        array $detail = [],
    ): self {
        return new self(
            $action,
            $subjectType,
            $subjectId,
            $tenantId,
            $productId,
            $userId,
            $projectId,
            $requestId,
            $detail,
        );
    }
}
