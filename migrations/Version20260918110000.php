<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A public demonstration page, switched on from the console (2026-09-18).
 *
 * `/demo` shows what the platform hosts — products with their offers on
 * sale, organisations with their holdings, subscriptions and people with
 * their roles — to anybody, with no session. That is a deliberate leak of
 * what everywhere else is a membership's answer, so it is off until a
 * platform administrator turns it on, and turning it on is a permission of
 * its own rather than a side of `staff.demo.reset`: wiping the world and
 * publishing it are different trusts. The switch is a platform setting
 * (`platform_settings.demo_page`), like the default tenant and the menus.
 */
final class Version20260918110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'staff.demo.publish: the console may show the public demonstration page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.demo.publish', 'Show or hide the public demonstration page')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.demo.publish'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM platform_settings WHERE key = 'demo_page'");
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id = (SELECT id FROM platform_permissions WHERE code = 'staff.demo.publish')
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.demo.publish'");
    }
}
