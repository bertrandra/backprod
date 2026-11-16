<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * Share links (ADR-073).
 *
 * Every method that lists or changes links takes a {@see Project}, which can
 * only be obtained from a lookup already scoped to the caller's tenant,
 * product and {@see Reach}: there is no way to address a link by its id
 * alone, so a link on somebody else's project is not refused — it is not
 * found.
 *
 * The one read that takes no project is {@see findLiveByTokenHash()}, for the
 * public page, and what it answers names its own scope: the caller has none.
 */
interface ProjectShareRepository
{
    /**
     * Stores a new link on this project, live for $days days from now.
     */
    public function create(
        Project $project,
        string $tokenHash,
        ?string $displayName,
        ?string $recipientLabel,
        int $days,
        ?string $createdBy,
    ): ProjectShare;

    /**
     * Every link on the project, newest first, revoked and expired included —
     * the sender is owed the history of what they handed out.
     *
     * @return list<ProjectShare>
     */
    public function listFor(Project $project): array;

    /** Links still answering: not revoked, not expired. */
    public function countLiveFor(Project $project): int;

    public function find(Project $project, string $shareId): ?ProjectShare;

    /** Revokes it, once: a second revocation keeps the first date and actor. */
    public function revoke(ProjectShare $share, ?string $revokedBy): void;

    /**
     * A link that is neither revoked nor expired, by the hash of its token,
     * or null — one answer for every reason it is not there.
     */
    public function findLiveByTokenHash(string $tokenHash): ?ProjectShare;

    /** Counts one opening: how many times, and when last — nothing about who. */
    public function recordOpening(ProjectShare $share): void;
}
