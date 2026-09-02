<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * Storage for projects and their versions.
 *
 * Reads are scoped by tenant *and* product, always. Writes take a Project
 * rather than an id, and a Project can only come from a scoped read — so
 * there is no method on this port that can be called with an id a caller
 * guessed. Non-negotiable #8 is isolation by tenant; this is the shape that
 * makes forgetting it impossible rather than merely discouraged.
 */
interface ProjectRepository
{
    /**
     * @return list<Project>
     */
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array;

    public function countForTenant(string $tenantId, string $productId): int;

    /**
     * Null covers both "no such project" and "not yours" — they must be
     * indistinguishable, or an id becomes a way to probe other tenants.
     */
    public function find(string $tenantId, string $productId, string $projectId): ?Project;

    /**
     * Returns the project as stored, not as submitted: the document has been
     * through JSONB by then, and the caller should see what was kept.
     */
    public function create(ProjectDraft $draft): Project;

    public function update(Project $project, ProjectChanges $changes): Project;

    public function delete(Project $project): void;

    /**
     * Newest first.
     *
     * @return list<ProjectVersion>
     */
    public function listVersions(Project $project): array;

    public function findVersion(Project $project, string $versionId): ?ProjectVersion;

    /**
     * Records the project's current state as the next version.
     *
     * Implementations must number versions under a lock on the project row:
     * the number is derived from the existing maximum, and two concurrent
     * snapshots must not both read the same one.
     */
    public function snapshot(Project $project, ?string $label, ?string $createdBy): ProjectVersion;

    /**
     * Puts a version's contents back onto the project.
     *
     * Implementations must snapshot the current state first, in the same
     * transaction. Restoring is otherwise the one destructive operation in
     * the versioning model — it would overwrite work that was never captured,
     * and the feature exists precisely so that cannot happen.
     */
    public function restore(Project $project, ProjectVersion $version, ?string $restoredBy): Project;

    /**
     * A new project with the same document, in the same tenant and product.
     *
     * The copy starts an empty version history: the versions belong to the
     * original's story, and attaching them to a new project would claim
     * snapshots were taken of a project that did not exist yet.
     */
    public function duplicate(Project $project, string $name, ?string $createdBy): Project;
}
