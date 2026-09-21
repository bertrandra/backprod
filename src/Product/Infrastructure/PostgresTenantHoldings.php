<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\TenantHoldings;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

final class PostgresTenantHoldings implements TenantHoldings
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function holds(string $tenantId, string $productId): bool
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM tenant_products WHERE tenant_id = :tenant AND product_id = :product)',
            ['tenant' => $tenantId, 'product' => $productId],
        );
    }
}
