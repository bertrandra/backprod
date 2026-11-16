<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Domain\ProjectShare;
use App\Project\Domain\SharedProject;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Two shapes for a share link, for two readers who must not see the same
 * thing (ADR-073).
 *
 * The sender's ({@see one()}) is everything about the link except its token,
 * which is never read back. The stranger's ({@see shared()}) is the project's
 * name, schema, document and date, the sender's chosen display name, and when
 * the link ends — and nothing else: no tenant, no holder, no creator, no
 * description, no counter, no id.
 */
final class ProjectSharePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(ProjectShare $share): array
    {
        return [
            'id' => $share->id,
            'project_id' => $share->projectId,
            'display_name' => $share->displayName,
            'recipient_label' => $share->recipientLabel,
            // Derived by the read, against the database's clock: a client
            // comparing `expires_at` with its own clock would disagree with
            // the server for as long as the two clocks do.
            'status' => $share->status,
            'created_by' => $share->createdBy,
            'created_at' => self::moment($share->createdAt),
            'expires_at' => self::moment($share->expiresAt),
            'revoked_at' => $share->revokedAt === null ? null : self::moment($share->revokedAt),
            'open_count' => $share->openCount,
            'last_opened_at' => $share->lastOpenedAt === null ? null : self::moment($share->lastOpenedAt),
        ];
    }

    /**
     * @param list<ProjectShare> $shares
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $shares): array
    {
        return array_map(self::one(...), $shares);
    }

    /**
     * @return array<string, mixed>
     */
    public static function shared(SharedProject $shared): array
    {
        return [
            'project' => [
                'name' => $shared->project->name,
                'schema_version' => $shared->project->schemaVersion,
                // Untouched (§16): the document is the Core's, and the page
                // that reads it is the product's.
                'document' => $shared->project->document,
                'updated_at' => self::moment($shared->project->updatedAt),
            ],
            'shared_by' => $shared->share->displayName,
            'expires_at' => self::moment($shared->share->expiresAt),
        ];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
