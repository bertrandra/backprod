<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two platform permissions that gated nothing, removed (2026-09-22).
 *
 * `staff.jobs.read` and `staff.jobs.manage` arrived with the queue
 * (`Version20260903110000`) for platform-wide job routes — read the queue,
 * enqueue, cancel — that were never built. The two queue reads that do exist
 * went behind `admin.health.read` instead, deliberately, so support can see
 * whether the runner is alive and what it is carrying
 * (`AdminDirectoryTest::testSupportStaffMayStillSeeTheQueue`).
 *
 * A permission no route requires is not harmless: the identities document
 * listed it as something PLATFORM_ADMIN holds, and `gate:permissions`
 * reported it on every run as defined but unused. A right nobody can
 * exercise is a false promise in the matrix, and the gate's line was the
 * platform saying so. Nobody loses anything here: nothing ever checked them.
 */
final class Version20260922090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove staff.jobs.read and staff.jobs.manage, which no route ever required';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                 SELECT id FROM platform_permissions WHERE code IN ('staff.jobs.read', 'staff.jobs.manage')
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code IN ('staff.jobs.read', 'staff.jobs.manage')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.jobs.read', 'Read the queue across the platform'),
                ('staff.jobs.manage', 'Enqueue and cancel platform-wide jobs')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code IN ('staff.jobs.read', 'staff.jobs.manage')
            SQL);
    }
}
