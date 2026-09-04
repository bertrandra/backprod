<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Audit\Domain\AuditEntry;
use App\Audit\Domain\AuditReader;

/**
 * Reading the platform's trail (§30).
 *
 * Thin on purpose. There is no business decision to make about a list of
 * things that already happened, and a service that started interpreting them
 * would be a second opinion about the past.
 *
 * The two filters are the two questions an incident is actually investigated
 * with: everything that touched this customer, and everything that happened
 * in this one request. The second is why `request_id` is a column.
 */
final class AuditTrail
{
    public function __construct(private readonly AuditReader $entries)
    {
    }

    /**
     * @return array{entries: list<AuditEntry>, total: int, limit: int, offset: int}
     */
    public function search(
        ?string $tenantId,
        ?string $requestId,
        int $limit,
        int $offset,
    ): array {
        return [
            'entries' => $this->entries->recent($tenantId, $requestId, $limit, $offset),
            'total' => $this->entries->count($tenantId, $requestId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
