<?php

declare(strict_types=1);

namespace App\Demo\Infrastructure;

use App\Demo\Domain\DemoPage;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * The demonstration page in PostgreSQL: a platform setting for the switch,
 * and five reads for the contents — one per list, joined in PHP by id,
 * because the page is small and a single query with four LEFT JOINs would
 * multiply rows for nothing.
 */
final class PostgresDemoPage implements DemoPage
{
    public const KEY = 'demo_page';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isPublished(): bool
    {
        $value = $this->connection->fetchOne(
            "SELECT value->>'enabled' FROM platform_settings WHERE key = :key",
            ['key' => self::KEY],
        );

        return $value === 'true';
    }

    public function publish(bool $published): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode(['enabled' => $published], JSON_THROW_ON_ERROR)],
        );
    }

    public function contents(): array
    {
        $default = $this->connection->fetchOne(
            "SELECT value->>'tenant_id' FROM platform_settings WHERE key = 'default_tenant'",
        );

        // --- products, with the offers on sale right now (an ACTIVE version
        // inside its window), advertised or not: it is a demonstration.
        $products = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, code, name FROM products WHERE active ORDER BY code') as $row) {
            $products[Row::string($row, 'id')] = ['code' => Row::string($row, 'code'), 'name' => Row::string($row, 'name'), 'offers' => []];
        }

        $offers = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT ON (o.id)
                       o.product_id, o.code, o.name, o.publicly_listed, p.code AS plan,
                       v.billing_period, v.price_minor_units, v.currency
                  FROM offers o
                  JOIN plans p ON p.id = o.plan_id
                  JOIN offer_versions v ON v.offer_id = o.id
                 WHERE v.status = 'ACTIVE'
                   AND v.valid_from <= now()
                   AND (v.valid_until IS NULL OR v.valid_until > now())
                 ORDER BY o.id, v.valid_from DESC, v.version DESC
                SQL,
        );

        foreach ($offers as $row) {
            $productId = Row::string($row, 'product_id');

            if (!isset($products[$productId])) {
                continue;
            }

            $products[$productId]['offers'][] = [
                'code' => Row::string($row, 'code'),
                'name' => Row::string($row, 'name'),
                'plan' => Row::string($row, 'plan'),
                'billing_period' => Row::string($row, 'billing_period'),
                'price' => ['minor_units' => Row::integer($row, 'price_minor_units'), 'currency' => Row::string($row, 'currency')],
                'publicly_listed' => Row::boolean($row, 'publicly_listed'),
            ];
        }

        foreach ($products as &$product) {
            usort($product['offers'], static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        }
        unset($product);

        // --- tenants
        $tenants = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, slug, name, join_policy FROM tenants ORDER BY name') as $row) {
            $id = Row::string($row, 'id');
            $tenants[$id] = [
                'slug' => Row::string($row, 'slug'),
                'name' => Row::string($row, 'name'),
                'is_default' => $id === $default,
                'join_policy' => Row::string($row, 'join_policy'),
                'products' => [],
                'subscriptions' => [],
                'members' => [],
            ];
        }

        $holdings = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tp.tenant_id, p.code
                  FROM tenant_products tp
                  JOIN products p ON p.id = tp.product_id
                 WHERE p.active
                 ORDER BY p.code
                SQL,
        );

        foreach ($holdings as $row) {
            $tenantId = Row::string($row, 'tenant_id');

            if (isset($tenants[$tenantId])) {
                $tenants[$tenantId]['products'][] = Row::string($row, 'code');
            }
        }

        $subscriptions = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.tenant_id, s.status, pr.code AS product, o.name AS offer, pl.name AS plan
                  FROM subscriptions s
                  JOIN offer_versions v ON v.id = s.offer_version_id
                  JOIN offers o ON o.id = v.offer_id
                  JOIN plans pl ON pl.id = o.plan_id
                  JOIN products pr ON pr.id = s.product_id
                 WHERE s.status = 'ACTIVE'
                 ORDER BY pr.code, s.created_at
                SQL,
        );

        foreach ($subscriptions as $row) {
            $tenantId = Row::string($row, 'tenant_id');

            if (isset($tenants[$tenantId])) {
                $tenants[$tenantId]['subscriptions'][] = [
                    'product' => Row::string($row, 'product'),
                    'offer' => Row::string($row, 'offer'),
                    'plan' => Row::string($row, 'plan'),
                    'status' => Row::string($row, 'status'),
                ];
            }
        }

        // One row per person per organisation, whatever the number of
        // products the membership is mirrored on (ADR-047).
        $members = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tm.tenant_id, u.display_name, u.email,
                       array_to_string(array_agg(DISTINCT r.code ORDER BY r.code), ',') AS roles
                  FROM tenant_members tm
                  JOIN users u ON u.id = tm.user_id
                  LEFT JOIN tenant_member_roles tmr
                         ON tmr.tenant_id = tm.tenant_id AND tmr.user_id = tm.user_id AND tmr.product_id = tm.product_id
                  LEFT JOIN roles r ON r.id = tmr.role_id
                 WHERE tm.status = 'ACTIVE' AND u.erased_at IS NULL
                 GROUP BY tm.tenant_id, u.id, u.display_name, u.email
                 ORDER BY u.email NULLS LAST, u.id
                SQL,
        );

        foreach ($members as $row) {
            $tenantId = Row::string($row, 'tenant_id');

            if (isset($tenants[$tenantId])) {
                $roles = Row::nullableString($row, 'roles');
                $tenants[$tenantId]['members'][] = [
                    'display_name' => Row::nullableString($row, 'display_name'),
                    'email' => Row::nullableString($row, 'email'),
                    'roles' => $roles === null || $roles === '' ? [] : explode(',', $roles),
                ];
            }
        }

        return ['products' => array_values($products), 'tenants' => array_values($tenants)];
    }
}
