<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The design system, saved under a name (2026-10-04).
 *
 * The console's palette screen reads `frontend/src/index.css` — colours in
 * both themes, font families, type scale — and saves what it read as a JSON
 * document here, under `default` unless told otherwise. Stored, not applied:
 * the stylesheet stays the design system.
 *
 * **A table and not a `platform_settings` key**, because these are records
 * with names and there may be several; one key holding a map of themes
 * would make every save a read-modify-write of all of them.
 *
 * **No row is seeded.** A seeded `default` would be the stylesheet as it
 * stood on the day this file was written, and a migration cannot follow the
 * stylesheet afterwards (ADR-016). `default` starts absent and is written the
 * first time somebody saves it.
 *
 * The name is checked here as well as in `ThemeName`, because it travels in
 * a path and a row the API could never address would be a row nobody can
 * replace. The document's shape is the application's to check — a CHECK
 * that understood it would be a second copy of `ThemeDocument`.
 *
 * `staff.design.manage`, PLATFORM_ADMIN alone.
 */
final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'themes: the design system saved under a name; staff.design.manage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE themes (
                name text PRIMARY KEY CHECK (name ~ '^[a-z0-9][a-z0-9-]{0,62}$'),
                document jsonb NOT NULL CHECK (jsonb_typeof(document) = 'object'),
                updated_by uuid REFERENCES users (id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.design.manage', 'Save the design system — palette, fonts and type scale — as a named theme')
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
        $this->addSql('DROP TABLE themes');
    }
}
