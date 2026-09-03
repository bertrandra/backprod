<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * The trail non-negotiable #21 requires.
 *
 * A port rather than a concrete writer for the usual reason, and one that
 * matters more here than most: an implementation that participates in the
 * caller's transaction is what lets an audit row and the act it justifies
 * be written together. An audit that can be lost while its act succeeds is
 * not an audit — it is a log line.
 */
interface StaffAccessLog
{
    public function record(StaffAccess $access): void;

    /**
     * The trail, most recent first, optionally narrowed to one tenant.
     *
     * Reading it is itself a staff act and is itself recorded — which is not
     * circular but the point: "who has been looking at this customer?" is a
     * question an auditor asks, and "who asked that question?" is the next
     * one.
     *
     * @return list<StaffAccessEntry>
     */
    public function recent(?string $tenantId, int $limit, int $offset): array;

    public function count(?string $tenantId): int;
}
