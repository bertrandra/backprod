<?php

declare(strict_types=1);

namespace App\Privacy\Domain;

/**
 * Carries out an erasure, in one transaction (#14, #15).
 *
 * One transaction because a half-erased person is worse than an un-erased
 * one: their messages gone and their identity intact, or their identity gone
 * and the audit log still naming them. The database already refuses the
 * second — `users_erasure_is_complete` — and the transaction covers the rest.
 */
interface ErasureRepository
{
    /**
     * What the audit trail calls this. Here rather than on the service
     * because the record is written by the adapter, inside the transaction
     * that does the erasing — and Infrastructure may not look upward into
     * Application to find a constant.
     */
    public const ACTION_ERASED = 'privacy.erased';

    /**
     * Whether this person has already been forgotten.
     *
     * Asked before doing anything, because a second erasure would record a
     * second and emptier outcome, and two records of the same act invite the
     * question of which one is true.
     */
    public function alreadyErased(string $userId): bool;

    /**
     * Anonymises the person and records what was kept.
     *
     * Never a DELETE. Five foreign keys into `users` are RESTRICT and four
     * are CASCADE, so a delete would either be refused or would silently take
     * notification history with it — including the notices kept for having
     * legal effect. The row stays and stops naming anybody.
     *
     * The audit record of the erasure is written **inside** the same
     * transaction. Written afterwards it could be lost while the erasure
     * stood, and an erasure nobody can attribute is the one thing this
     * operation must never become (#21's reasoning, applied to itself).
     */
    public function erase(
        string $subjectUserId,
        string $requestedByUserId,
        ?string $requestId,
    ): ErasureOutcome;
}
