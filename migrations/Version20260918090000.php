<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A USER can buy (2026-09-18, amending ADR-049).
 *
 * The operator's reading of self-service: somebody who signs up at an
 * organisation's root and chooses an offer pays for it there and then. Two
 * things stood in the way and both go here.
 *
 * **Paying was behind `billing.manage`**, which also covers issuing invoices,
 * credit notes and the billing profile — administrative acts a USER must
 * not have. So paying gets a permission of its own, `billing.pay`: open a
 * checkout, pay an invoice through the provider, retry a failed payment.
 * Both roles hold it. Issuing, crediting, refunding and marking an invoice
 * paid by hand stay with `billing.manage` / `payments.manage`, which stay
 * the administrator's. `subscription.manage` — subscribe, change, cancel —
 * goes to USER as well, at the operator's word.
 *
 * **The default join policy was APPROVAL**, under which a newcomer waits
 * for an administrator and can pay nothing. `OPEN` is added — in at once,
 * no domain check — and becomes the default; organisations that never
 * chose a policy move to it. APPROVAL, DOMAIN and INVITATION remain for
 * those that do choose.
 */
final class Version20260918090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.pay for everybody, subscription.manage for USER, OPEN join policy as the default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description)
            VALUES ('billing.pay', 'Open a checkout, pay an invoice, retry a payment')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code IN ('TENANT_ADMIN', 'USER')
               AND p.code = 'billing.pay'
            ON CONFLICT DO NOTHING
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code = 'USER'
               AND p.code = 'subscription.manage'
            ON CONFLICT DO NOTHING
            SQL);

        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT tenants_join_policy_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_join_policy_known CHECK (join_policy IN ('OPEN', 'INVITATION', 'DOMAIN', 'APPROVAL')),
                ALTER COLUMN join_policy SET DEFAULT 'OPEN'
            SQL);
        // Nobody chose APPROVAL: it was the column's default for one day.
        $this->addSql("UPDATE tenants SET join_policy = 'OPEN' WHERE join_policy = 'APPROVAL'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE tenants SET join_policy = 'APPROVAL' WHERE join_policy = 'OPEN'");
        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT tenants_join_policy_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_join_policy_known CHECK (join_policy IN ('INVITATION', 'DOMAIN', 'APPROVAL')),
                ALTER COLUMN join_policy SET DEFAULT 'APPROVAL'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
             WHERE role_id = (SELECT id FROM roles WHERE code = 'USER')
               AND permission_id = (SELECT id FROM permissions WHERE code = 'subscription.manage')
            SQL);
        $this->addSql("DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'billing.pay')");
        $this->addSql("DELETE FROM permissions WHERE code = 'billing.pay'");
    }
}
