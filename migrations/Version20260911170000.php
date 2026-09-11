<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff that can be granted, and a platform that cannot lose its last admin.
 *
 * M6.2 gave the platform staff identity and left the grant itself with no
 * route to it: `platform_staff` had no endpoint, no screen and no permission,
 * so the only way to create the first staff member — or any staff member —
 * was an INSERT typed by hand against production. That was defensible while
 * the platform had no installer. `deploy/siteground/setup.php` changed it: a
 * person now installs this themselves, signs in, and finds a console they
 * cannot enter and no way to let anybody else in either.
 *
 * Two things fix that, and only the second belongs in a migration:
 *
 * **`staff.grant`**, so granting is a permission somebody holds rather than
 * database access somebody has. PLATFORM_ADMIN holds it; the other three
 * platform roles do not, because a support engineer who can appoint support
 * engineers is not a support engineer.
 *
 * **The platform keeps at least one PLATFORM_ADMIN.** Revoking the last one
 * locks every person out of the console with no route back in except the SQL
 * this migration exists to stop anyone needing. So it is refused, and refused
 * *here* rather than in the service that revokes — the same reasoning as
 * every other invariant in this schema. A revoke is not the only way to reach
 * zero: deleting the user cascades into `platform_staff`, and a role swap is
 * an UPDATE. One check placed after the fact catches all three, because it
 * asks about the result rather than the act.
 */
final class Version20260911170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'staff.grant, and the invariant that the platform always keeps one admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.grant', 'Grant and revoke platform roles')
            SQL);

        // Explicitly, not by the cross join M6.2 used: that statement granted
        // PLATFORM_ADMIN every permission that existed *when it ran*, which is
        // a fact about that migration rather than a rule the table keeps. A
        // permission added later is granted later or not at all.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.grant'
            SQL);

        // AFTER, and asking about the resulting state rather than the row that
        // changed. A BEFORE trigger would have to work out what the table is
        // about to look like; this one looks.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION platform_keeps_an_admin() RETURNS trigger AS $$
            BEGIN
                -- Only when the row that changed was an administrator's. A
                -- platform that holds no PLATFORM_ADMIN at all — a database
                -- mid-install, or a test fixture with one support engineer in
                -- it — is not made worse by removing somebody who was never
                -- one, and refusing that would turn this into a trap for
                -- databases the rule was never true of. What is defended is
                -- *losing* the last administrator, not the absence of one.
                IF NOT EXISTS (
                    SELECT 1
                      FROM platform_roles
                     WHERE id = OLD.platform_role_id
                       AND code = 'PLATFORM_ADMIN'
                ) THEN
                    RETURN NULL;
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                      FROM platform_staff ps
                      JOIN platform_roles r ON r.id = ps.platform_role_id
                     WHERE r.code = 'PLATFORM_ADMIN'
                ) THEN
                    RAISE EXCEPTION
                        'platform_staff_keeps_an_admin: the platform must keep at least one '
                        'PLATFORM_ADMIN; grant the role to somebody else before revoking this one';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // A CONSTRAINT TRIGGER rather than a plain one, for the swap: handing
        // the platform over is "revoke mine, grant theirs", and in that order
        // it passes through zero admins on its way to one. A caller that wants
        // that may `SET CONSTRAINTS ALL DEFERRED` and be checked at COMMIT,
        // where the question is the one that matters. Left INITIALLY IMMEDIATE
        // so the ordinary mistake still fails on the statement that made it,
        // next to the code that caused it.
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER platform_staff_keeps_an_admin
                AFTER DELETE OR UPDATE ON platform_staff
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION platform_keeps_an_admin()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS platform_staff_keeps_an_admin ON platform_staff');
        $this->addSql('DROP FUNCTION IF EXISTS platform_keeps_an_admin()');

        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.grant'
                   )
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.grant'");
    }
}
