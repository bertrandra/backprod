<?php

declare(strict_types=1);

namespace App\Project\Service;

use App\Entitlement\Domain\QuotaPolicy;
use App\Project\Domain\DocumentPolicy;
use App\Project\Domain\Project;
use App\Project\Domain\ProjectChanges;
use App\Project\Domain\ProjectDraft;
use App\Project\Domain\ProjectRepository;
use App\Project\Domain\ProjectVersion;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Projects within one tenant and product.
 *
 * Every method takes the tenant and product from the caller's resolved
 * context and passes them into the lookup, so a project belonging to another
 * tenant is not "denied" — it is not found, which is the same answer an
 * invented id gets (§31).
 *
 * How many projects a tenant may hold is an entitlement, not a constant
 * here: the offer they subscribed to says, and QuotaPolicy enforces it
 * against the count of projects that actually exist.
 *
 * An offer that does not mention max_projects grants no projects at all,
 * rather than unlimited. Absence grants nothing, everywhere in this platform
 * — the alternative would make forgetting a line in a price list the way to
 * give something away.
 */
final class ProjectWorkspace
{
    public const NAME_MAX_LENGTH = 255;

    /**
     * The entitlement that says how many projects a tenant may hold.
     */
    public const QUOTA = 'max_projects';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly SchemaVersionPolicy $schemaVersions,
        private readonly DocumentPolicy $documents,
        private readonly QuotaPolicy $quotas,
    ) {
    }

    /**
     * @return array{projects: list<Project>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'projects' => $this->projects->listForTenant($tenantId, $productId, $limit, $offset),
            'total' => $this->projects->countForTenant($tenantId, $productId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function get(string $tenantId, string $productId, string $projectId): Project
    {
        $project = $this->projects->find($tenantId, $productId, $projectId);

        if ($project === null) {
            throw self::unknownProject();
        }

        return $project;
    }

    public function create(
        string $tenantId,
        string $productId,
        ?string $createdBy,
        string $name,
        ?string $description,
        int $schemaVersion,
        object $document,
    ): Project {
        // Quota first: a tenant at their limit should be told so before
        // being told their document is too deep. The cheapest refusal to act
        // on is the one that names what to do about it.
        $this->quotas->assertMayConsume($tenantId, $productId, self::QUOTA, $createdBy);
        $this->schemaVersions->assertSupported($productId, $schemaVersion);
        $this->documents->assertStorable($document);

        return $this->projects->create(new ProjectDraft(
            $tenantId,
            $productId,
            $name,
            $description,
            $schemaVersion,
            $document,
            $createdBy,
        ));
    }

    public function update(
        string $tenantId,
        string $productId,
        string $projectId,
        ProjectChanges $changes,
    ): Project {
        if ($changes->isEmpty()) {
            throw new BadRequestException(
                'NOTHING_TO_UPDATE',
                'The request body changed no field.',
            );
        }

        $project = $this->get($tenantId, $productId, $projectId);

        // Only content changes are held to the schema. Renaming a project
        // whose schema was retired must stay possible — otherwise a product
        // retiring a version would leave its customers unable to so much as
        // relabel their own work while they migrate.
        if ($changes->document !== null || $changes->schemaVersion !== null) {
            $this->schemaVersions->assertSupported(
                $productId,
                $changes->schemaVersion ?? $project->schemaVersion,
            );
        }

        if ($changes->document !== null) {
            $this->documents->assertStorable($changes->document);
        }

        return $this->projects->update($project, $changes);
    }

    public function delete(string $tenantId, string $productId, string $projectId): void
    {
        $this->projects->delete($this->get($tenantId, $productId, $projectId));
    }

    /**
     * @return list<ProjectVersion>
     */
    public function versions(string $tenantId, string $productId, string $projectId): array
    {
        return $this->projects->listVersions($this->get($tenantId, $productId, $projectId));
    }

    public function version(
        string $tenantId,
        string $productId,
        string $projectId,
        string $versionId,
    ): ProjectVersion {
        $version = $this->projects->findVersion(
            $this->get($tenantId, $productId, $projectId),
            $versionId,
        );

        if ($version === null) {
            throw new NotFoundException(
                'Project version not found.',
                [],
                'PROJECT_VERSION_NOT_FOUND',
            );
        }

        return $version;
    }

    public function snapshot(
        string $tenantId,
        string $productId,
        string $projectId,
        ?string $label,
        ?string $createdBy,
    ): ProjectVersion {
        return $this->projects->snapshot(
            $this->get($tenantId, $productId, $projectId),
            $label,
            $createdBy,
        );
    }

    /**
     * Restoring is always allowed, even onto a schema version the product no
     * longer accepts for new writes.
     *
     * The alternative — history you can list and read but never recover — is
     * worse than holding a document the product has moved past, and the
     * update path already refuses to *edit* one. A snapshot exists to be
     * restorable; configuration changing afterwards must not retroactively
     * make it decorative.
     */
    public function restore(
        string $tenantId,
        string $productId,
        string $projectId,
        string $versionId,
        ?string $restoredBy,
    ): Project {
        $project = $this->get($tenantId, $productId, $projectId);
        $version = $this->version($tenantId, $productId, $projectId, $versionId);

        return $this->projects->restore($project, $version, $restoredBy);
    }

    public function duplicate(
        string $tenantId,
        string $productId,
        string $projectId,
        ?string $name,
        ?string $createdBy,
    ): Project {
        $project = $this->get($tenantId, $productId, $projectId);

        // A duplicate is a new project and counts against the same quota.
        // Exempting it would make the limit trivially avoidable.
        $this->quotas->assertMayConsume($tenantId, $productId, self::QUOTA, $createdBy);

        // A copy carries the original's schema version rather than being
        // refused when that version has been retired: it is the same document,
        // and duplicating is a read of it, not a new authoring decision.
        return $this->projects->duplicate(
            $project,
            $name ?? self::copyNameFor($project->name),
            $createdBy,
        );
    }

    /**
     * A caller that cares what the copy is called should say so; this is the
     * fallback, kept short enough to stay within the name limit even when the
     * original already fills it.
     */
    private static function copyNameFor(string $name): string
    {
        $suffix = ' (copy)';
        $room = self::NAME_MAX_LENGTH - mb_strlen($suffix);

        return mb_substr($name, 0, $room) . $suffix;
    }

    private static function unknownProject(): NotFoundException
    {
        return new NotFoundException('Project not found.', [], 'PROJECT_NOT_FOUND');
    }
}
