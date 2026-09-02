<?php

declare(strict_types=1);

namespace App\Project\Domain;

use DateTimeImmutable;

/**
 * A complete snapshot of a project at a moment in time (§17).
 *
 * A snapshot rather than a delta, deliberately: §17 keeps deltas for when
 * volume justifies them, and a full copy means restoring reads exactly one
 * row and cannot be defeated by a gap in a chain.
 *
 * It carries the schema version as it stood, not the project's current one.
 * A version written under schema 1 must still say 1 after the project has
 * moved to 2, or restoring it would relabel an old document as a new one.
 */
final class ProjectVersion
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly int $versionNumber,
        public readonly ?string $label,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $schemaVersion,
        public readonly object $document,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
