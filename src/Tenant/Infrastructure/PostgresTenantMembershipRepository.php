<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantMembershipRepository implements TenantMembershipRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findForUserAndProduct(string $userId, string $productId): array
    {
        // roles is a PostgreSQL text[]; DBAL hands array columns back as the
        // raw `{a,b}` literal, which is ambiguous once a value contains a
        // comma or quote. Converting to JSON in the database avoids writing a
        // parser for that literal here.
        //
        // Ordered by tenant_id so the "several memberships" list in a
        // TENANT_SELECTION_REQUIRED response is stable between requests.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tenant_id, user_id, product_id, array_to_json(roles) AS roles
                FROM tenant_members
                WHERE user_id = :userId
                  AND product_id = :productId
                ORDER BY tenant_id
                SQL,
            [
                'userId' => $userId,
                'productId' => $productId,
            ],
        );

        $memberships = [];

        foreach ($rows as $row) {
            $tenantId = $row['tenant_id'] ?? null;
            $member = $row['user_id'] ?? null;
            $product = $row['product_id'] ?? null;

            if (!is_string($tenantId) || !is_string($member) || !is_string($product)) {
                continue;
            }

            $memberships[] = new TenantMembership(
                $tenantId,
                $member,
                $product,
                $this->roles($row['roles'] ?? null),
            );
        }

        return $memberships;
    }

    /**
     * @return list<string>
     */
    private function roles(mixed $encoded): array
    {
        if (!is_string($encoded)) {
            return [];
        }

        $decoded = json_decode($encoded, true);

        if (!is_array($decoded)) {
            return [];
        }

        $roles = [];

        foreach ($decoded as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
