<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Platform staff: the one identity allowed to work across the tenant
 * boundary, and the trail it leaves when it does (§12.2).
 *
 * Everything the platform has built so far derives authority from membership:
 * a request resolves (tenant, user, product) and gets exactly what that
 * membership allows. That is the right default and it has no answer for
 * "support needs to read this customer's ticket", because support is not a
 * member of the customer.
 *
 * The shape here is governed by one decision. Staff roles live in their own
 * tables rather than as extra rows in `roles` and `tenant_members`, because
 * the two axes must not be confusable:
 *
 *   tenant membership    what may this person do in THEIR company
 *   platform staff role  what may this person do ACROSS companies
 *
 * A single table holding both would make a forgotten `WHERE tenant_id = ?`
 * into privilege escalation. Kept apart, the same mistake returns nothing.
 * The permission catalogues are separate for the same reason: a staff
 * permission code cannot be granted by a tenant role because it is not in
 * the table tenant roles draw from.
 *
 * `staff_access_log` is what makes non-negotiable #21 enforceable rather than
 * aspirational. Crossing the boundary is legitimate and it is never free: the
 * row naming who looked at whose data is written in the same transaction as
 * the act it justifies.
 */
final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform staff identity, its permissions and its audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_roles (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                CONSTRAINT platform_roles_code_unique UNIQUE (code),
                CONSTRAINT platform_roles_code_not_blank CHECK (btrim(code) <> '')
            )
            SQL);

        // A separate catalogue from `permissions`, which tenant roles draw
        // from. Sharing one table would let a single mistaken join grant a
        // support permission to a tenant member; with two, the code simply
        // is not there to grant.
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_permissions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code TEXT NOT NULL,
                description TEXT NOT NULL,
                CONSTRAINT platform_permissions_code_unique UNIQUE (code),
                CONSTRAINT platform_permissions_code_is_staff_scoped
                    CHECK (code ~ '^(staff|support)\.')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE platform_role_permissions (
                platform_role_id UUID NOT NULL
                    REFERENCES platform_roles (id) ON DELETE CASCADE,
                platform_permission_id UUID NOT NULL
                    REFERENCES platform_permissions (id) ON DELETE CASCADE,
                PRIMARY KEY (platform_role_id, platform_permission_id)
            )
            SQL);

        // Who holds a platform role, and who said so.
        //
        // granted_by is recorded because a staff grant is the most powerful
        // write in the system: it is the one act that can create an identity
        // able to read every tenant. ON DELETE SET NULL rather than CASCADE —
        // the grantee leaving must not erase the fact that someone granted
        // this, and RESTRICT would make deleting a departed admin impossible.
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_staff (
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                platform_role_id UUID NOT NULL
                    REFERENCES platform_roles (id) ON DELETE RESTRICT,
                granted_by UUID REFERENCES users (id) ON DELETE SET NULL,
                granted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (user_id, platform_role_id)
            )
            SQL);

        // Non-negotiable #21: no staff access to tenant data is silent.
        //
        // Both actor and subject are RESTRICT, not CASCADE. The record of who
        // read a tenant's data must outlive an appetite to delete the tenant,
        // and the record of *who did the reading* must outlive an appetite to
        // delete the reader. Either cascade would let the most interesting
        // rows in this table be removed by deleting something else.
        //
        // That a staff member who has read customer data can no longer be
        // deleted is the intended consequence, not an oversight: revoking
        // access means deleting their grant in `platform_staff`, which
        // cascades cleanly, and an access trail whose actor column can be
        // emptied is not a trail. §26 already separates legal retention from
        // RGPD erasure; a security audit log sits on the retention side.
        //
        // product_id is nullable because some staff reads are not
        // product-scoped — enumerating tenants, say — and a fabricated
        // product id would be worse than an absent one.
        $this->addSql(<<<'SQL'
            CREATE TABLE staff_access_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                staff_user_id UUID NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                tenant_id UUID REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID REFERENCES products (id) ON DELETE RESTRICT,
                action TEXT NOT NULL,
                resource_type TEXT NOT NULL,
                resource_id TEXT,
                permission TEXT NOT NULL,
                detail JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT staff_access_log_action_not_blank CHECK (btrim(action) <> ''),
                CONSTRAINT staff_access_log_resource_type_not_blank
                    CHECK (btrim(resource_type) <> ''),
                CONSTRAINT staff_access_log_permission_not_blank
                    CHECK (btrim(permission) <> ''),
                CONSTRAINT staff_access_log_detail_is_object
                    CHECK (jsonb_typeof(detail) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX staff_access_log_tenant_idx
                ON staff_access_log (tenant_id, occurred_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX staff_access_log_staff_idx
                ON staff_access_log (staff_user_id, occurred_at DESC)
            SQL);

        // Reference data, as with tenant roles in M2: the platform cannot
        // authorise a staff request without it.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_roles (code, name) VALUES
                ('PLATFORM_ADMIN', 'Platform administrator'),
                ('SUPPORT_ADMIN', 'Support'),
                ('FINANCE_ADMIN', 'Finance'),
                ('SALES_ADMIN', 'Sales')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.self.read', 'Read one''s own platform roles'),
                ('staff.tenants.read', 'List and read tenants across the platform'),
                ('staff.access_log.read', 'Read the staff access trail'),
                ('support.read', 'Read a tenant''s support conversations'),
                ('support.respond', 'Reply to a tenant''s support conversations')
            SQL);

        // Every staff member may see what they themselves hold — it grants
        // no access to tenant data and makes "why was I refused?" answerable
        // without a support round trip.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
            FROM platform_roles r
            CROSS JOIN platform_permissions p
            WHERE p.code = 'staff.self.read'
               OR (r.code = 'PLATFORM_ADMIN')
               OR (r.code = 'SUPPORT_ADMIN' AND p.code IN (
                       'staff.tenants.read', 'support.read', 'support.respond'
                   ))
               OR (r.code IN ('FINANCE_ADMIN', 'SALES_ADMIN') AND p.code = 'staff.tenants.read')
            SQL);
    }

    /**
     * Deliberately not reversible (ADR-016).
     *
     * Dropping `staff_access_log` would destroy the record of who read whose
     * data, which is the one thing non-negotiable #21 exists to keep. A
     * down() that quietly erases an audit trail is worse than no down() at
     * all: it turns "we can prove who looked" into "we could have".
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping the staff tables would destroy the access trail #21 requires.',
        );
    }
}
