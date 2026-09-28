<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Proving the address is the operator's choice, and it is off (ADR-063).
 *
 * ADR-061 gave every self-service sign-up a deadline and made the tenant
 * surface answer `EMAIL_UNCONFIRMED` past it. The operator, on 2026-09-28:
 * *"user registration confirmation is an option in platform admin, no need
 * by default"*. So the demand becomes a platform setting, and the setting is
 * off unless somebody turns it on.
 *
 * **Nothing is written here.** The setting lives in `platform_settings` under
 * the key `sign_up`, and its absence *is* the default — the same shape the
 * storefront's `after_sign_up` and the default tenant already have. Seeding a
 * row saying `false` would make "nobody has decided" and "somebody decided
 * no" look identical, and the first is what every deployment starts from.
 *
 * **No account is touched either**, and it is worth saying why not. Rows
 * written before today carry the deadline ADR-061 gave them, and the
 * enforcement now asks the setting before it refuses anybody — so with the
 * setting off, which is where every deployment now starts, a deadline in the
 * past refuses nothing. Clearing the column would instead throw away the one
 * record of what those people were told, and an operator who switches the
 * demand on would have no way to know it.
 *
 * `staff.sign_up.manage` is what the console holds to change it, and
 * PLATFORM_ADMIN alone holds it. Deliberately not `staff.catalog.manage`,
 * which the rest of the storefront screen answers to: that permission
 * decides what is advertised, and whether a stranger must prove an address
 * before reaching anything is not a commercial decision. A support engineer
 * given the price list must not thereby be able to switch off the proof.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Asking a new account to prove its address is a platform setting, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.sign_up.manage', 'Decide whether a new account must prove its address before it may use the platform')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.sign_up.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM platform_settings WHERE key = 'sign_up'");
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.sign_up.manage'
                   )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.sign_up.manage'");
    }
}
