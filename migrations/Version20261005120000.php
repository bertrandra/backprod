<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The staff access log goes (2026-10-05).
 *
 * The operator's decision: the console reads an organisation's data without
 * giving a reason, and nothing staff do is recorded — not the reads, and not
 * the changes. `staff_access_log` held both, and with nothing writing it and
 * nothing reading it, keeping it would keep a table that says less every day
 * than it appears to. Its history is deleted with it; that is what was chosen.
 *
 * `staff.access_log.read`, the permission its screen was read under, goes too:
 * a permission for a screen that does not exist is a box somebody ticks for
 * nothing.
 *
 * Not the product access log (`product_access_log`): that one records what a
 * product's *key* reached, a different question, and stays.
 *
 * `down()` brings the table back as it last was, empty — history deleted is
 * not history restored — and the permission with PLATFORM_ADMIN's grant of it.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'drop staff_access_log and staff.access_log.read: the console reads without a reason, and nothing is recorded';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE staff_access_log');

        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.access_log.read'
                   )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.access_log.read'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE staff_access_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                staff_user_id UUID NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                tenant_id UUID REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID REFERENCES products (id) ON DELETE RESTRICT,
                action TEXT NOT NULL,
                resource_type TEXT NOT NULL,
                resource_id TEXT,
                permission TEXT NOT NULL,
                detail JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                purpose TEXT CHECK (purpose IN ('SUPPORT_REQUEST', 'BILLING_INVESTIGATION', 'INCIDENT', 'SECURITY_REVIEW', 'LEGAL_REQUEST')),
                reason TEXT CHECK (reason IS NULL OR length(btrim(reason)) BETWEEN 8 AND 500),
                CONSTRAINT staff_access_log_action_not_blank CHECK (btrim(action) <> ''),
                CONSTRAINT staff_access_log_resource_type_not_blank CHECK (btrim(resource_type) <> ''),
                CONSTRAINT staff_access_log_permission_not_blank CHECK (btrim(permission) <> ''),
                CONSTRAINT staff_access_log_detail_is_object CHECK (jsonb_typeof(detail) = 'object'),
                CONSTRAINT staff_access_log_motivated CHECK ((purpose IS NULL) = (reason IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX staff_access_log_tenant_idx ON staff_access_log (tenant_id, occurred_at DESC)');
        $this->addSql('CREATE INDEX staff_access_log_staff_idx ON staff_access_log (staff_user_id, occurred_at DESC)');
        $this->addSql('CREATE INDEX staff_access_log_purpose_idx ON staff_access_log (purpose, occurred_at DESC) WHERE purpose IS NOT NULL');

        $this->addSql("INSERT INTO platform_permissions (code, description) VALUES ('staff.access_log.read', 'Read the staff access trail')");
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.access_log.read'
            SQL);
    }
}
