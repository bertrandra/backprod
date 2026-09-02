<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * A validated request to create a project.
 *
 * Exists so the repository is handed something already checked rather than a
 * spread of loose arguments in an order nobody can remember. Tenant and
 * product come from the resolved context, never from the request body
 * (ADR-015) — a caller cannot name the tenant they are writing into.
 */
final class ProjectDraft
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $schemaVersion,
        public readonly object $document,
        public readonly ?string $createdBy,
    ) {
    }
}
