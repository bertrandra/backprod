<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The permission behind the queue's liveness signal (R10).
 *
 * No new table: `job_runs` has recorded every pass since M7 and nothing ever
 * read it back. R10's gap was never storage, it was that a cron which stopped
 * firing looks exactly like a quiet queue to anybody outside the database.
 *
 * **Support holds this one, unlike the financial dashboard.** "Why has my
 * export not arrived?" is a support question, and the honest answer is
 * sometimes "the runner has not run since Tuesday". The signal carries no
 * customer data — timestamps and counts — so letting support see it costs
 * nothing and letting them guess costs a wrong answer to a customer.
 */
final class Version20260904100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permission to read whether the job queue is still being polled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.health.read', 'Read whether the job queue is still being polled')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code = 'admin.health.read'
               AND r.code IN ('PLATFORM_ADMIN', 'SUPPORT_ADMIN')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                   SELECT id FROM platform_permissions WHERE code = 'admin.health.read'
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'admin.health.read'");
    }
}
