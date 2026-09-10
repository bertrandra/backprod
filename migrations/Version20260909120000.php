<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * White label: a tenant's own colours and logo (§7, §12.1).
 *
 * Keyed by `(tenant_id, product_id)` rather than by tenant alone, because
 * §12.1 lets one tenant use several products and a company that white-labels
 * two of them has no reason to want the same shade in both. The composite
 * primary key is also what makes "one skin per tenant per product" a fact
 * rather than something the service remembers to check.
 *
 * **The colours are constrained to `#rrggbb` by the database.** These values
 * are interpolated into a stylesheet or a `style` attribute by whatever
 * renders them, and a colour column that accepts arbitrary text is a
 * stylesheet injection with extra steps. Six hex digits cannot express
 * `red;} body{display:none`.
 *
 * **The logo is an asset, not bytes.** `assets` already sniffs the content
 * type from the bytes, refuses SVG for the stored-XSS reason ADR-028 gives,
 * and serves through signed links. A second path for images would be a
 * second place to get all of that right.
 *
 * ON DELETE SET NULL on the logo, deliberately: deleting the asset should
 * leave a tenant with no logo, not with a skin that cannot be loaded.
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-tenant, per-product skin: colours constrained to hex, logo as an asset';
    }

    public function up(Schema $schema): void
    {
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

        // Changing the skin is configuring the tenant, so it rides on the
        // permission that already means that. What it *additionally* needs is
        // the `white_label` entitlement (§12.1's feature list) — the role says
        // this person may configure the tenant, the plan says the tenant has
        // this feature to configure. Neither implies the other.
        //
        // Reading needs no new permission: a client has to know how to render
        // itself before it knows what the tenant bought, and the answer to a
        // tenant with no skin is the same either way.
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('skin.manage', 'Set the tenant''s colours and logo')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code = 'TENANT_ADMIN'
               AND p.code = 'skin.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
             WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'skin.manage')
            SQL);

        $this->addSql("DELETE FROM permissions WHERE code = 'skin.manage'");
        $this->addSql('DROP TABLE IF EXISTS tenant_skins');
    }
}
