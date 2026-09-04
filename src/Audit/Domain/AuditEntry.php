<?php

declare(strict_types=1);

namespace App\Audit\Domain;

use DateTimeImmutable;

/**
 * One thing that happened, and the four keys §30 says it must be findable by.
 *
 * Deliberately not "a log line with some context attached". The correlation
 * keys — tenant, product, user, project, request — are fields because the
 * questions they answer are the ones an incident is actually investigated
 * with: everything in this request, everything to this tenant, everything
 * this person did. A message string cannot be asked those.
 *
 * `actorForgottenAt` is the one thing here that can change after the fact,
 * and it says the person named has since been erased (#14, #15). It is a
 * separate fact from having had no actor at all: a nightly sweep and a
 * forgotten operator both leave `userId` null, and only the stamp tells them
 * apart.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $id,
        public readonly DateTimeImmutable $occurredAt,
        public readonly string $action,
        public readonly string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $tenantId,
        public readonly ?string $productId,
        public readonly ?string $userId,
        public readonly ?string $projectId,
        public readonly ?string $requestId,
        public readonly array $detail,
        public readonly ?DateTimeImmutable $actorForgottenAt,
    ) {
    }

    /**
     * Whether the person who did this has since been erased.
     *
     * Asked rather than inferred from a null `userId`, because that null has
     * two meanings and only one of them is an erasure.
     */
    public function actorWasForgotten(): bool
    {
        return $this->actorForgottenAt !== null;
    }
}
