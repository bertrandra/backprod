<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One permission for the operational directory (§7's `/admin` block).
 *
 * M8 gave `/admin` four surfaces — the audit trail, the financial dashboard,
 * queue liveness and erasure — and each got the permission its audience
 * needed. The five listings §7 also names have none, and they do not all
 * belong under the same one.
 *
 * `admin.directory.read` covers **who the platform's customers are**: the
 * tenant list and the people in it. It is deliberately narrower than the
 * finance permission it sits beside, and PLATFORM_ADMIN alone holds it —
 * not FINANCE_ADMIN, not SALES_ADMIN, and not SUPPORT_ADMIN.
 *
 * The reason is `/admin/users`, which is the only admin surface in this
 * platform that returns personal data. Support already has what it needs to
 * answer a customer's question: `/staff/tenants/{id}`, which names one tenant
 * and writes an access-log row for having looked. A list of every person on
 * the platform answers no support question and would be an unaudited read of
 * everyone at once — so it stays with the role that already holds erasure.
 *
 * The other three listings reuse permissions that already exist and already
 * mean the right thing: subscriptions and invoices are money, so
 * `admin.finance.read`; jobs are the queue, so `admin.health.read` — the same
 * permission that answers "has the runner run since Tuesday" for support.
 */
final class Version20260909110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin.directory.read for the tenant and user listings, PLATFORM_ADMIN only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.directory.read', 'List the platform''s tenants and the people in them')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code = 'admin.directory.read'
               AND r.code = 'PLATFORM_ADMIN'
            SQL);

        // The listings order by creation and page through it, so the index
        // that serves them is the one that already has to exist for the sort
        // to avoid a full sort of every tenant on the platform.
        $this->addSql('CREATE INDEX IF NOT EXISTS tenants_created_idx ON tenants (created_at DESC, id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS users_created_idx ON users (created_at DESC, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS users_created_idx');
        $this->addSql('DROP INDEX IF EXISTS tenants_created_idx');

        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                   SELECT id FROM platform_permissions WHERE code = 'admin.directory.read')
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'admin.directory.read'");
    }
}
