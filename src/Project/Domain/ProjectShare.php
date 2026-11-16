<?php

declare(strict_types=1);

namespace App\Project\Domain;

use DateTimeImmutable;

/**
 * One share link on a project, as its sender sees it (ADR-073).
 *
 * **There is no token here.** It is shown once, inside the URL the creating
 * call answers with, and only its hash is stored; a row that carried it could
 * be presented, which is the one thing a stored credential must not be.
 *
 * The status is derived when the row is read, against the database's clock,
 * rather than kept as a column: an expiry is a date, and a column saying
 * "expired" would be right until the night it stopped being updated.
 */
final class ProjectShare
{
    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';
    public const REVOKED = 'REVOKED';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $projectId,
        /** What the reader is shown as the sender, or null. */
        public readonly ?string $displayName,
        /** The sender's own note of whom it was for; never shown to the reader. */
        public readonly ?string $recipientLabel,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly ?string $revokedBy,
        public readonly int $openCount,
        public readonly ?DateTimeImmutable $lastOpenedAt,
        /** {@see ACTIVE}, {@see EXPIRED} or {@see REVOKED}, decided by the query that read it. */
        public readonly string $status,
    ) {
    }
}
