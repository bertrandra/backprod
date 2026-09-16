<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The demonstration world can be rebuilt from the console.
 *
 * Until now the only way to put the demo back was a shell:
 * `composer run demo:reset`. A deployment installed from the demonstration
 * world — which is what the installer now seeds — has no shell to speak of,
 * and a demonstration that has been walked through, paid on and refunded is
 * one somebody wants back the way it started.
 *
 * `staff.demo.reset` is what the console holds to do it, and PLATFORM_ADMIN
 * alone holds that. A permission of its own rather than a side of
 * `staff.products.manage`: emptying every business table is the widest
 * destructive act on this platform, and creating a product is not the same
 * trust. The seeder refuses it anyway while a product that is not the
 * demonstration's exists — the rule is on what exists, not on what is asked.
 */
final class Version20260916110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The console can reset the demonstration world, behind a permission of its own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.demo.reset', 'Empty every business table and seed the demonstration world afresh')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.demo.reset'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.demo.reset'
                   )
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.demo.reset'");
    }
}
