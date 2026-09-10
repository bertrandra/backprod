<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Domain\Project;
use App\Project\Domain\ProjectVersion;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for a project and its versions, wherever they are returned.
 *
 * Listings deliberately omit the document. A project document runs to
 * hundreds of kilobytes, and a list of fifty would be a response nobody
 * asked for — a client listing projects wants to choose one, and fetches it
 * when it has. The single-project representation is the one that carries the
 * document.
 *
 * The document is passed through untouched: it is the Core's, and the
 * backend has no business reshaping it on the way out (§16).
 */
final class ProjectPresenter
{
    /**
     * @return array{
     *     id: string, name: string, description: string|null, schema_version: int,
     *     created_by: string|null, created_at: string, updated_at: string,
     *     deleted_at: string|null
     * }
     */
    public static function summary(Project $project): array
    {
        // tenant_id and product_id are not returned: the caller already knows
        // both — they are the context the request was made in — and echoing
        // them invites clients to treat them as something they may vary.
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'schema_version' => $project->schemaVersion,
            'created_by' => $project->createdBy,
            'created_at' => self::moment($project->createdAt),
            'updated_at' => self::moment($project->updatedAt),
            // Null on every live project, which is every project a client sees
            // unless it asked for `?deleted=true`. Present rather than omitted,
            // so a client never has to infer the state from which list it
            // happens to be reading (R13).
            'deleted_at' => $project->deletedAt === null ? null : self::moment($project->deletedAt),
        ];
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $projects): array
    {
        return array_map(self::summary(...), $projects);
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Project $project): array
    {
        return self::summary($project) + ['document' => $project->document];
    }

    /**
     * @return array{
     *     id: string, version_number: int, label: string|null, name: string,
     *     description: string|null, schema_version: int, created_by: string|null,
     *     created_at: string
     * }
     */
    public static function versionSummary(ProjectVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->versionNumber,
            'label' => $version->label,
            'name' => $version->name,
            'description' => $version->description,
            'schema_version' => $version->schemaVersion,
            'created_by' => $version->createdBy,
            'created_at' => self::moment($version->createdAt),
        ];
    }

    /**
     * @param list<ProjectVersion> $versions
     *
     * @return list<array<string, mixed>>
     */
    public static function versions(array $versions): array
    {
        return array_map(self::versionSummary(...), $versions);
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ProjectVersion $version): array
    {
        return self::versionSummary($version) + ['document' => $version->document];
    }

    /**
     * RFC 3339 in UTC, converted rather than assumed: the offset a timestamp
     * arrives with depends on the database session, and a client should never
     * have to reconcile two offsets for the same instant.
     */
    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
