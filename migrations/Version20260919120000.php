<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The words the platform's mails say are the platform administrator's to
 * change (2026-09-19): `staff.mail.manage`, for the template editor and the
 * tester in Console → Setup → Mail. The templates themselves are a platform
 * setting (`platform_settings.mail_templates`), like the menus.
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'staff.mail.manage: edit the mail templates and send a test';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.mail.manage', 'Edit the words the platform''s mails say, and send a test')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.mail.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM platform_settings WHERE key = 'mail_templates'");
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id = (SELECT id FROM platform_permissions WHERE code = 'staff.mail.manage')
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.mail.manage'");
    }
}
