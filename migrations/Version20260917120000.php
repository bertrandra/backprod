<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The platform's own settings, and the first of them: the menu setup.
 *
 * Products have had a key/value configuration since M2; the platform itself
 * had none, because until now nothing was decided platform-wide from a
 * screen. The operator asked on 2026-09-17 for a setup that chooses, per
 * kind of person — platform administrator, tenant administrator, user —
 * which menu entries the shell shows, and whether an entry with nothing
 * behind it is shown at all. That is one document about the whole platform,
 * so it gets the table a product's configuration already has, keyed the
 * same way.
 *
 * `staff.navigation.manage` is what the console holds to change it, and
 * PLATFORM_ADMIN alone holds that: deciding what every customer's
 * administrator can find is the platform administrator's decision, not the
 * catalogue's or support's.
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform settings, with the menu setup behind a permission of its own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_settings (
                key TEXT PRIMARY KEY,
                value JSONB NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT platform_settings_key_is_word CHECK (key ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT platform_settings_value_is_object CHECK (jsonb_typeof(value) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.navigation.manage', 'Choose which menu entries the platform shows each kind of person')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.navigation.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.navigation.manage'
                   )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.navigation.manage'");
        $this->addSql('DROP TABLE platform_settings');
    }
}
