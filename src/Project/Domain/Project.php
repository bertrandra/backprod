<?php

declare(strict_types=1);

namespace App\Project\Domain;

use DateTimeImmutable;

/**
 * One project: a document the Core owns, plus the facts the backend needs to
 * find it again.
 *
 * The document's shape is not the backend's business (§16) — it is stored as
 * JSONB and handed back unchanged. What is the backend's business is who it
 * belongs to and which schema it speaks, and those are columns.
 *
 * It carries its own tenant and product, which is what makes the repository
 * methods safe: they take a Project rather than an id, and a Project can only
 * be obtained from a lookup that was already scoped to the caller's context.
 */
final class Project
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $schemaVersion,
        public readonly object $document,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        /**
         * When somebody deleted it, or null while it is live (R13).
         *
         * A date rather than a flag, for the reason every other removal in this
         * platform uses one: "it is gone" and "it went on Tuesday" are different
         * amounts of information, and only the second survives a support
         * conversation.
         */
        public readonly ?DateTimeImmutable $deletedAt = null,
    ) {
    }

    /** Deleted, and therefore invisible to every list until it is restored. */
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
