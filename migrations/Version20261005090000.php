<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The logo belongs to the organisation; the skin goes (2026-10-05).
 *
 * `tenant_skins` held, per `(tenant, product)`, two colours and a logo, sold
 * as `white_label`. The colours were never painted by anything — the
 * palettes answer that question now — and the logo is part of what the
 * organisation *is*, like its name: one for the organisation, set by its
 * administrator under `tenant.manage`, tied to no offer.
 *
 * Each organisation keeps the logo it set most recently, in whichever product
 * that was. The colours are dropped with the table: nothing read them.
 *
 * `white_label` is **retired, never deleted** (ADR-052): offer versions and
 * live entitlements may still name it. No new offer may grant it; anybody who
 * holds it keeps a word that no longer gates anything.
 *
 * `skin.manage` stays, and now means only what it has done since the palettes
 * arrived: choose which palette the organisation's screens wear.
 */
final class Version20261005090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tenants.logo_asset_id, copied from the latest skin; drop tenant_skins; retire white_label';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants ADD COLUMN logo_asset_id uuid REFERENCES assets (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            UPDATE tenants t
               SET logo_asset_id = latest.logo_asset_id
              FROM (
                    SELECT DISTINCT ON (tenant_id) tenant_id, logo_asset_id
                      FROM tenant_skins
                     WHERE logo_asset_id IS NOT NULL
                     ORDER BY tenant_id, updated_at DESC
                   ) latest
             WHERE latest.tenant_id = t.id
            SQL);

        $this->addSql('DROP TABLE tenant_skins');

        $this->addSql("UPDATE features SET active = false WHERE code = 'white_label'");

        $this->addSql("UPDATE permissions SET description = 'Choose which palette the organisation''s screens wear' WHERE code = 'skin.manage'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE permissions SET description = 'Set the tenant''s colours and logo' WHERE code = 'skin.manage'");
        $this->addSql("UPDATE features SET active = true WHERE code = 'white_label'");

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_skins (
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                primary_color TEXT,
                accent_color TEXT,
                logo_asset_id UUID REFERENCES assets (id) ON DELETE SET NULL,
                updated_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, product_id),
                CONSTRAINT tenant_skins_primary_is_hex
                    CHECK (primary_color IS NULL OR primary_color ~ '^#[0-9a-f]{6}$'),
                CONSTRAINT tenant_skins_accent_is_hex
                    CHECK (accent_color IS NULL OR accent_color ~ '^#[0-9a-f]{6}$')
            )
            SQL);

        // Back onto the product the asset was filed under: that is the one
        // the logo was uploaded in.
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_skins (tenant_id, product_id, logo_asset_id)
            SELECT t.id, a.product_id, t.logo_asset_id
              FROM tenants t
              JOIN assets a ON a.id = t.logo_asset_id
            SQL);

        $this->addSql('ALTER TABLE tenants DROP COLUMN logo_asset_id');
    }
}
