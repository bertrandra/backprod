<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalises roles and introduces permissions.
 *
 * The previous migration carried roles as a text[] on tenant_members with a
 * CHECK constraint. That was enough to resolve context, but §13 requires
 * access rules to be centralised rather than scattered, and a check
 * constraint cannot express what a role *allows* — only which strings are
 * spellable. Roles now map to permissions, and authorisation asks about
 * permissions.
 */
final class Version20260902140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles, permissions and their assignment to tenant members';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN display_name TEXT');

        $this->addSql(<<<'SQL'
            CREATE TABLE roles (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                CONSTRAINT roles_code_unique UNIQUE (code)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE permissions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code TEXT NOT NULL,
                description TEXT NOT NULL,
                CONSTRAINT permissions_code_unique UNIQUE (code)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE role_permissions (
                role_id UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
                permission_id UUID NOT NULL REFERENCES permissions (id) ON DELETE CASCADE,
                PRIMARY KEY (role_id, permission_id)
            )
            SQL);

        // Reference data, not fixtures: the platform cannot authorise anything
        // without it, so it belongs in the migration rather than a seeder that
        // an environment might not have run.
        $this->addSql(<<<'SQL'
            INSERT INTO roles (code, name) VALUES
                ('TENANT_ADMIN', 'Tenant administrator'),
                ('USER', 'Member')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('account.read', 'Read own account'),
                ('account.manage', 'Update own account'),
                ('tenant.read', 'Read the current tenant'),
                ('tenant.manage', 'Update the current tenant'),
                ('members.read', 'List tenant members'),
                ('members.manage', 'Add, change and remove tenant members')
            SQL);

        // An administrator gets everything; a member may read the tenant and
        // its member list, and manage only their own account.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code = 'TENANT_ADMIN')
               OR (r.code = 'USER' AND p.code IN ('account.read', 'account.manage', 'tenant.read', 'members.read'))
            SQL);

        // ON DELETE RESTRICT on role_id: removing a role that people still
        // hold must fail loudly rather than silently strip their access.
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_member_roles (
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                product_id UUID NOT NULL,
                role_id UUID NOT NULL REFERENCES roles (id) ON DELETE RESTRICT,
                PRIMARY KEY (tenant_id, user_id, product_id, role_id),
                CONSTRAINT tenant_member_roles_member_fk
                    FOREIGN KEY (tenant_id, user_id, product_id)
                    REFERENCES tenant_members (tenant_id, user_id, product_id) ON DELETE CASCADE
            )
            SQL);

        // Carry existing assignments across before the column goes.
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
            SELECT tm.tenant_id, tm.user_id, tm.product_id, r.id
            FROM tenant_members tm
            CROSS JOIN LATERAL unnest(tm.roles) AS role_code
            JOIN roles r ON r.code = role_code
            SQL);

        $this->addSql('ALTER TABLE tenant_members DROP CONSTRAINT tenant_members_roles_not_empty');
        $this->addSql('ALTER TABLE tenant_members DROP CONSTRAINT tenant_members_roles_known');
        $this->addSql('ALTER TABLE tenant_members DROP COLUMN roles');
    }

    /**
     * Deliberately not reversible (ADR-016).
     *
     * Reversing would mean recreating tenant_members.roles from the join
     * table and dropping roles, permissions and their assignments. Any role
     * created after this migration has no column to go back to, so a down()
     * would quietly lose access grants. Rolling back means restoring a
     * backup, and saying so is more honest than a method that appears to work.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reversing this migration would discard role assignments that the text[] column cannot represent.',
        );
    }
}
