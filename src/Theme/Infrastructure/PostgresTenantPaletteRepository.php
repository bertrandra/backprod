<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Theme\Domain\Palette;
use App\Theme\Domain\PaletteAssignments;
use App\Theme\Domain\PaletteName;
use App\Theme\Domain\TenantPaletteRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantPaletteRepository implements TenantPaletteRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function holds(string $tenantId, string $productId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM tenant_products WHERE tenant_id = :tenant AND product_id = :product',
            ['tenant' => $tenantId, 'product' => $productId],
        ) !== false;
    }

    public function selected(string $tenantId, string $productId): ?Palette
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.name, p.document, p.updated_at
                  FROM tenant_palettes t
                  JOIN palettes p ON p.name = t.palette
                 WHERE t.tenant_id = :tenant AND t.product_id = :product
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : PostgresPaletteRepository::hydrate($row);
    }

    public function select(string $tenantId, string $productId, ?PaletteName $name, string $chosenBy): void
    {
        if ($name === null) {
            $this->connection->executeStatement(
                'DELETE FROM tenant_palettes WHERE tenant_id = :tenant AND product_id = :product',
                ['tenant' => $tenantId, 'product' => $productId],
            );

            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_palettes (tenant_id, product_id, palette, chosen_by)
                VALUES (:tenant, :product, :palette, :by)
                ON CONFLICT (tenant_id, product_id) DO UPDATE
                   SET palette = EXCLUDED.palette,
                       chosen_by = EXCLUDED.chosen_by,
                       chosen_at = now()
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'palette' => $name->value, 'by' => $chosenBy],
        );
    }

    public function assignments(int $limit, int $offset): PaletteAssignments
    {
        $products = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, code, name FROM products ORDER BY code') as $row) {
            $products[] = ['id' => self::text($row, 'id'), 'code' => self::text($row, 'code'), 'name' => self::text($row, 'name')];
        }

        $tenants = [];
        $page = $this->connection->fetchAllAssociative(
            'SELECT id, name, slug FROM tenants ORDER BY name, id LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        foreach ($page as $row) {
            $tenants[self::text($row, 'id')] = [
                'id' => self::text($row, 'id'),
                'name' => self::text($row, 'name'),
                'slug' => self::text($row, 'slug'),
                'palettes' => [],
            ];
        }

        if ($tenants !== []) {
            // One cell per product the tenant holds, and only those: the
            // left join says which palette, or none.
            $cells = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT tp.tenant_id, tp.product_id, p.palette
                      FROM tenant_products tp
                      LEFT JOIN tenant_palettes p ON p.tenant_id = tp.tenant_id AND p.product_id = tp.product_id
                     WHERE tp.tenant_id IN (:tenants)
                    SQL,
                ['tenants' => array_keys($tenants)],
                ['tenants' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );

            foreach ($cells as $cell) {
                $tenant = self::text($cell, 'tenant_id');
                $palette = $cell['palette'] ?? null;

                if (isset($tenants[$tenant])) {
                    $tenants[$tenant]['palettes'][self::text($cell, 'product_id')] = is_string($palette) ? $palette : null;
                }
            }
        }

        $total = $this->connection->fetchOne('SELECT count(*) FROM tenants');

        return new PaletteAssignments($products, array_values($tenants), is_int($total) ? $total : (int) (is_string($total) ? $total : 0));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!is_string($value)) {
            throw new \UnexpectedValueException("A row is missing {$column}.");
        }

        return $value;
    }
}
