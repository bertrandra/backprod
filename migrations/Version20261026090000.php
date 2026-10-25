<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A VAT return belongs to its issuer (2026-10-26).
 *
 * `vat_reporting_periods` had no owner. Its totals summed
 * `issuer_tenant_id IS NULL` — the platform's own return — yet the only routes
 * reading and closing it were tenant routes behind `tax.manage`, which every
 * customer's administrator holds. So any organisation could read the VAT the
 * platform charged every other customer, and close the platform's declaration
 * for a whole country, one way and for good.
 *
 * The same rule as gapless numbering (ADR-057): a series belongs to whoever
 * raised the documents, and so does the return that declares them. A period
 * now names its issuer — an organisation, or NULL for the platform — and the
 * unique window is per issuer. Every period that exists was the platform's,
 * which is what NULL says, so nothing is rewritten.
 *
 * The platform's return moves to `/api/v1/admin/vat-reports`, behind two
 * platform permissions: `admin.tax.read` and `admin.tax.manage`, held by
 * PLATFORM_ADMIN and FINANCE_ADMIN. SUPPORT_ADMIN and SALES_ADMIN do not
 * declare taxes.
 *
 * `down()` refuses once an organisation has a period of its own: dropping the
 * column would hand it to the platform.
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vat_reporting_periods name their issuer; the platform return moves behind admin.tax.read and admin.tax.manage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE vat_reporting_periods
                ADD COLUMN issuer_tenant_id uuid REFERENCES tenants (id) ON DELETE RESTRICT
            SQL);

        $this->addSql('DROP INDEX vat_reporting_periods_unique');

        // NULLS NOT DISTINCT: the platform is one issuer, so two platform
        // periods over the same window are the duplicate this index exists to
        // refuse — the same reading the numbering indexes give a NULL issuer.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX vat_reporting_periods_unique
                ON vat_reporting_periods (issuer_tenant_id, jurisdiction, starts_on, ends_on)
                NULLS NOT DISTINCT
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.tax.read', 'Read the platform''s own VAT return'),
                ('admin.tax.manage', 'Close a period of the platform''s own VAT return')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code IN ('admin.tax.read', 'admin.tax.manage')
               AND r.code IN ('PLATFORM_ADMIN', 'FINANCE_ADMIN')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM vat_reporting_periods WHERE issuer_tenant_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'an organisation has a VAT period of its own; dropping issuer_tenant_id would hand it to the platform';
                END IF;
            END
            $$
            SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                SELECT id FROM platform_permissions WHERE code IN ('admin.tax.read', 'admin.tax.manage')
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code IN ('admin.tax.read', 'admin.tax.manage')");

        $this->addSql('DROP INDEX vat_reporting_periods_unique');
        $this->addSql('ALTER TABLE vat_reporting_periods DROP COLUMN issuer_tenant_id');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX vat_reporting_periods_unique
                ON vat_reporting_periods (jurisdiction, starts_on, ends_on)
            SQL);
    }
}
