<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Palettes, and the permission to change them (2026-10-04).
 *
 * A palette is the design system as a document — colours in both modes, font
 * families, type scale — under a name. **Only the platform administrator
 * changes one** (`staff.design.manage`, PLATFORM_ADMIN alone): a palette is
 * shared by every organisation wearing it, so editing it is the platform's
 * decision and never one organisation's.
 *
 * `position` is the order they are offered in, and UNIQUE so that two
 * palettes created at once cannot take the same place.
 *
 * **No foreign key to anybody**, deliberately: the five palettes the next
 * migration seeds are reference data, like roles and permissions, and nothing
 * a test empties may reach them. Who changed one is in `staff_access_log`.
 */
final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'palettes, edited by the platform administrator alone; staff.design.manage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE palettes (
                name text PRIMARY KEY CHECK (name ~ '^[a-z0-9][a-z0-9-]{0,62}$'),
                position integer NOT NULL UNIQUE,
                document jsonb NOT NULL CHECK (jsonb_typeof(document) = 'object'),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.design.manage', 'Edit the palettes, and choose which one each organisation wears in each product')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.design.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.design.manage'
                   )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.design.manage'");
        $this->addSql('DROP TABLE palettes');
    }
}
