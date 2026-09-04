<?php

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * Reading the trail, kept apart from writing it.
 *
 * Two interfaces over one table, because the two capabilities have nothing
 * to do with each other. Everything writes; one admin surface reads. If
 * recording a cancellation meant depending on an interface that can also
 * read every tenant's history, then every module that records anything would
 * hold that reach — and the only thing standing between it and use would be
 * that nobody had called the method yet.
 */
interface AuditReader
{
    /**
     * The trail, most recent first, optionally narrowed.
     *
     * @return list<AuditEntry>
     */
    public function recent(?string $tenantId, ?string $requestId, int $limit, int $offset): array;

    public function count(?string $tenantId, ?string $requestId): int;
}
