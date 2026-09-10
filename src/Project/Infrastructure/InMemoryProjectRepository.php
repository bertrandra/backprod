<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\Project;
use App\Project\Domain\ProjectChanges;
use App\Project\Domain\ProjectDraft;
use App\Project\Domain\ProjectRepository;
use App\Project\Domain\ProjectVersion;
use DateTimeImmutable;
use RuntimeException;

/**
 * Projects held in memory, for tests that are about the HTTP surface.
 *
 * It mirrors the Postgres adapter's *behaviour* — scoping, ordering, version
 * numbering, snapshot-before-restore — so an endpoint test proves the same
 * thing either way. What it deliberately does not mirror is JSONB's
 * normalisation of stored documents, because that is PostgreSQL's behaviour
 * and imitating it here would only let a test agree with a guess. The
 * round-trip guarantees are verified against a real database.
 */
final class InMemoryProjectRepository implements ProjectRepository
{
    /** @var array<string, Project> */
    private array $projects = [];

    /** @var array<string, ProjectVersion> */
    private array $versions = [];

    public function listForTenant(
        string $tenantId,
        string $productId,
        int $limit,
        int $offset,
        bool $deleted = false,
    ): array {
        $matching = array_values(array_filter(
            $this->projects,
            static fn (Project $p): bool => $p->tenantId === $tenantId
                && $p->productId === $productId
                && $p->isDeleted() === $deleted,
        ));

        // Most recently touched first, id as the tie-break — the same order
        // the SQL adapter asks PostgreSQL for.
        usort($matching, static function (Project $a, Project $b): int {
            return ($b->updatedAt <=> $a->updatedAt) ?: strcmp($a->id, $b->id);
        });

        return array_values(array_slice($matching, $offset, $limit));
    }

    public function countForTenant(string $tenantId, string $productId, bool $deleted = false): int
    {
        return count(array_filter(
            $this->projects,
            static fn (Project $p): bool => $p->tenantId === $tenantId
                && $p->productId === $productId
                && $p->isDeleted() === $deleted,
        ));
    }

    public function find(string $tenantId, string $productId, string $projectId): ?Project
    {
        $project = $this->projects[$projectId] ?? null;

        if ($project === null || $project->tenantId !== $tenantId || $project->productId !== $productId) {
            return null;
        }

        return $project;
    }

    public function create(ProjectDraft $draft): Project
    {
        $now = new DateTimeImmutable();

        $project = new Project(
            self::id(),
            $draft->tenantId,
            $draft->productId,
            $draft->name,
            $draft->description,
            $draft->schemaVersion,
            self::copy($draft->document),
            $draft->createdBy,
            $now,
            $now,
        );

        $this->projects[$project->id] = $project;

        return $project;
    }

    public function update(Project $project, ProjectChanges $changes): Project
    {
        $updated = new Project(
            $project->id,
            $project->tenantId,
            $project->productId,
            $changes->name ?? $project->name,
            $changes->descriptionProvided ? $changes->description : $project->description,
            $changes->schemaVersion ?? $project->schemaVersion,
            $changes->document === null ? $project->document : self::copy($changes->document),
            $project->createdBy,
            $project->createdAt,
            new DateTimeImmutable(),
        );

        $this->projects[$project->id] = $updated;

        return $updated;
    }

    /**
     * Recoverable here too, and the versions stay (R13).
     *
     * This adapter used to `unset` the project *and its versions*, which mirrored
     * the SQL cascade faithfully. Keeping the mirror faithful is the whole value
     * of an in-memory adapter: if this one still destroyed history, a unit test
     * would pass against behaviour the database no longer has.
     */
    public function delete(Project $project, ?string $deletedBy): void
    {
        $this->projects[$project->id] = $this->withDeletedAt($project, new DateTimeImmutable());
    }

    public function undelete(Project $project): void
    {
        $this->projects[$project->id] = $this->withDeletedAt($project, null);
    }

    private function withDeletedAt(Project $project, ?DateTimeImmutable $deletedAt): Project
    {
        return new Project(
            $project->id,
            $project->tenantId,
            $project->productId,
            $project->name,
            $project->description,
            $project->schemaVersion,
            $project->document,
            $project->createdBy,
            $project->createdAt,
            $project->updatedAt,
            $deletedAt,
        );
    }

    public function listVersions(Project $project): array
    {
        $versions = array_values(array_filter(
            $this->versions,
            static fn (ProjectVersion $v): bool => $v->projectId === $project->id,
        ));

        usort(
            $versions,
            static fn (ProjectVersion $a, ProjectVersion $b): int => $b->versionNumber <=> $a->versionNumber,
        );

        return $versions;
    }

    public function findVersion(Project $project, string $versionId): ?ProjectVersion
    {
        $version = $this->versions[$versionId] ?? null;

        return $version !== null && $version->projectId === $project->id ? $version : null;
    }

    public function snapshot(Project $project, ?string $label, ?string $createdBy): ProjectVersion
    {
        return $this->snapshotOf($this->reload($project), $label, $createdBy);
    }

    public function restore(Project $project, ProjectVersion $version, ?string $restoredBy): Project
    {
        $current = $this->reload($project);

        $this->snapshotOf(
            $current,
            sprintf('Before restoring version %d', $version->versionNumber),
            $restoredBy,
        );

        $restored = new Project(
            $current->id,
            $current->tenantId,
            $current->productId,
            $version->name,
            $version->description,
            $version->schemaVersion,
            self::copy($version->document),
            $current->createdBy,
            $current->createdAt,
            new DateTimeImmutable(),
        );

        $this->projects[$restored->id] = $restored;

        return $restored;
    }

    public function duplicate(Project $project, string $name, ?string $createdBy): Project
    {
        $source = $this->reload($project);
        $now = new DateTimeImmutable();

        $copy = new Project(
            self::id(),
            $source->tenantId,
            $source->productId,
            $name,
            $source->description,
            $source->schemaVersion,
            self::copy($source->document),
            $createdBy,
            $now,
            $now,
        );

        $this->projects[$copy->id] = $copy;

        return $copy;
    }

    private function snapshotOf(Project $project, ?string $label, ?string $createdBy): ProjectVersion
    {
        $highest = 0;

        foreach ($this->versions as $version) {
            if ($version->projectId === $project->id) {
                $highest = max($highest, $version->versionNumber);
            }
        }

        $snapshot = new ProjectVersion(
            self::id(),
            $project->id,
            $highest + 1,
            $label,
            $project->name,
            $project->description,
            $project->schemaVersion,
            self::copy($project->document),
            $createdBy,
            new DateTimeImmutable(),
        );

        $this->versions[$snapshot->id] = $snapshot;

        return $snapshot;
    }

    /**
     * The stored state, not the caller's copy — the Postgres adapter reads
     * the row inside the transaction for the same reason.
     */
    private function reload(Project $project): Project
    {
        return $this->projects[$project->id] ?? $project;
    }

    /**
     * A deep copy, so a stored document cannot be mutated through a reference
     * a caller kept. Round-tripping through JSON keeps objects as objects,
     * which decoding into arrays would not.
     */
    private static function copy(object $document): object
    {
        $encoded = json_encode($document);
        $decoded = $encoded === false ? null : json_decode($encoded, false);

        if (!is_object($decoded)) {
            throw new RuntimeException('A project document must be encodable as a JSON object.');
        }

        return $decoded;
    }

    private static function id(): string
    {
        // Shaped like a UUID so tests exercise the same id handling as
        // production, including the format check the adapters apply.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
