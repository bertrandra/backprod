<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Audit\Domain\AuditEntry;

/**
 * What an audit entry looks like on the wire.
 *
 * `actor` carries both the id and whether it was erased, so a reader can
 * tell an act nobody performed from one whose performer has since been
 * forgotten. Collapsing those into a bare null would quietly turn every
 * erasure into a system action.
 */
final class AdminPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function auditEntry(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurredAt->format(DATE_ATOM),
            'action' => $entry->action,
            'subject' => [
                'type' => $entry->subjectType,
                'id' => $entry->subjectId,
            ],
            'actor' => [
                'user_id' => $entry->userId,
                'forgotten' => $entry->actorWasForgotten(),
                'forgotten_at' => $entry->actorForgottenAt?->format(DATE_ATOM),
            ],
            // §30's correlation keys, named as the caller would search by.
            'tenant_id' => $entry->tenantId,
            'product_id' => $entry->productId,
            'project_id' => $entry->projectId,
            'request_id' => $entry->requestId,
            'detail' => $entry->detail,
        ];
    }
}
