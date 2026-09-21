<?php

declare(strict_types=1);

namespace App\Webhook\Infrastructure;

use App\Shared\Database\Uuid;
use App\Webhook\Domain\ProductEvents;
use Doctrine\DBAL\Connection;

/**
 * The outbox row, written by the act (ADR-051 §5).
 *
 * Every INSERT here is `INSERT … SELECT` from `products`, filtered on an
 * address being set and the product being active: a product with no
 * address hears nothing, and no row is left waiting for one. The detail is
 * stored as given; the envelope — event id, type, when, which product and
 * tenant — is on the row's own columns and assembled at send time.
 */
final class PostgresProductEvents implements ProductEvents
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function publish(string $type, string $productId, ?string $tenantId, array $detail): void
    {
        if (!Uuid::isValid($productId) || ($tenantId !== null && !Uuid::isValid($tenantId))) {
            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO webhook_deliveries (product_id, event_id, event_type, tenant_id, payload)
                SELECT p.id, gen_random_uuid(), :type, :tenant, CAST(:payload AS jsonb)
                  FROM products p
                 WHERE p.id = :product AND p.active AND p.webhook_url IS NOT NULL
                SQL,
            ['type' => $type, 'tenant' => $tenantId, 'payload' => self::encode($detail), 'product' => $productId],
        );
    }

    public function publishForTenant(string $type, string $tenantId, array $detail): void
    {
        if (!Uuid::isValid($tenantId)) {
            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO webhook_deliveries (product_id, event_id, event_type, tenant_id, payload)
                SELECT p.id, gen_random_uuid(), :type, tp.tenant_id, CAST(:payload AS jsonb)
                  FROM tenant_products tp
                  JOIN products p ON p.id = tp.product_id
                 WHERE tp.tenant_id = :tenant AND p.active AND p.webhook_url IS NOT NULL
                SQL,
            ['type' => $type, 'tenant' => $tenantId, 'payload' => self::encode($detail)],
        );
    }

    public function publishToAll(string $type, array $detail): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO webhook_deliveries (product_id, event_id, event_type, tenant_id, payload)
                SELECT p.id, gen_random_uuid(), :type, NULL, CAST(:payload AS jsonb)
                  FROM products p
                 WHERE p.active AND p.webhook_url IS NOT NULL
                SQL,
            ['type' => $type, 'payload' => self::encode($detail)],
        );
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function encode(array $detail): string
    {
        return json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
