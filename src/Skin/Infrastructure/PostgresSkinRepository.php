<?php

declare(strict_types=1);

namespace App\Skin\Infrastructure;

use App\Shared\Database\Row;
use App\Skin\Domain\Skin;
use App\Skin\Domain\SkinRepository;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * The skin, upserted.
 *
 * A partial update on a row that may not exist yet is the awkward case. The
 * distinction that matters is between a field the caller did not mention and
 * a field they set to null: the first keeps what was there, the second clears
 * it. `coalesce` cannot tell those apart — it treats both as "no value" — so
 * the resolution happens in PHP against the current row, and the upsert
 * writes three settled values.
 *
 * Doing it in SQL instead would mean a second parameter per column carrying
 * "was this one named", which is more machinery than three fields deserve.
 */
final class PostgresSkinRepository implements SkinRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function forTenant(string $tenantId, string $productId): Skin
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT primary_color, accent_color, logo_asset_id
                  FROM tenant_skins
                 WHERE tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        if ($row === false) {
            return Skin::unset();
        }

        return new Skin(
            Row::nullableString($row, 'primary_color'),
            Row::nullableString($row, 'accent_color'),
            Row::nullableString($row, 'logo_asset_id'),
        );
    }

    public function save(string $tenantId, string $productId, array $changes, ?string $actorId): Skin
    {
        $current = $this->forTenant($tenantId, $productId);

        $values = [
            'primary_color' => array_key_exists('primary_color', $changes)
                ? $changes['primary_color'] : $current->primaryColor,
            'accent_color' => array_key_exists('accent_color', $changes)
                ? $changes['accent_color'] : $current->accentColor,
            'logo_asset_id' => array_key_exists('logo_asset_id', $changes)
                ? $changes['logo_asset_id'] : $current->logoAssetId,
        ];

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO tenant_skins (
                    tenant_id, product_id, primary_color, accent_color, logo_asset_id, updated_by
                )
                VALUES (:tenantId, :productId, :primary, :accent, CAST(:logo AS uuid), CAST(:actor AS uuid))
                ON CONFLICT (tenant_id, product_id) DO UPDATE
                    SET primary_color = EXCLUDED.primary_color,
                        accent_color  = EXCLUDED.accent_color,
                        logo_asset_id = EXCLUDED.logo_asset_id,
                        updated_by    = EXCLUDED.updated_by,
                        updated_at    = now()
                RETURNING primary_color, accent_color, logo_asset_id
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'primary' => $values['primary_color'],
                'accent' => $values['accent_color'],
                'logo' => $values['logo_asset_id'],
                'actor' => $actorId,
            ],
        );

        if ($row === false) {
            // Unreachable: the upsert inserts or updates, and both return.
            throw new RuntimeException('The skin was written and did not come back.');
        }

        return new Skin(
            Row::nullableString($row, 'primary_color'),
            Row::nullableString($row, 'accent_color'),
            Row::nullableString($row, 'logo_asset_id'),
        );
    }
}
