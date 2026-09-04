<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The admin surface's permissions, and the trail §30 asks for.
 *
 * Two things arrive together because neither is much use alone: a boundary
 * nobody can cross, and a record of everyone who crossed it.
 *
 * **The permission namespace widens rather than the table splitting.** M6.2
 * constrained `platform_permissions.code` to `staff.` and `support.` so a
 * tenant grant could not reach a staff one — the code is simply not in the
 * table tenant roles draw from. `admin.` joins that same catalogue for the
 * same reason: /admin is a different audience from /staff, not a different
 * identity model, and giving it a second table would give it a second
 * authorisation path to get wrong. §10.1 lists both surfaces; M6.2 already
 * defined FINANCE_ADMIN and SALES_ADMIN with nothing to grant them.
 *
 * **The audit log is append-only in the database, not by convention.** A
 * trail an application can edit is a trail that proves nothing, and "we do
 * not update it" is a promise rather than a constraint. A trigger refuses
 * UPDATE and DELETE outright, the way the VAT period closure does.
 *
 * §30 names four correlation keys — tenant, user, project, request — and all
 * four are columns rather than JSON, because the questions they answer
 * ("everything in this request", "everything this operator did") are
 * indexable ones.
 */
final class Version20260904070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin permission namespace and the append-only audit log';
    }

    public function up(Schema $schema): void
    {
        // --- the boundary -----------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE platform_permissions
                DROP CONSTRAINT platform_permissions_code_is_staff_scoped
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE platform_permissions
                ADD CONSTRAINT platform_permissions_code_is_platform_scoped
                    CHECK (code ~ '^(staff|support|admin)\.')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.audit.read', 'Read the platform audit trail')
            SQL);

        // Granted explicitly rather than by a rule that swept the table when
        // M6.2 ran: a permission added later is not retroactively held by
        // anyone, which is the safe direction for that to fail in.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code = 'admin.audit.read'
               AND r.code = 'PLATFORM_ADMIN'
            SQL);

        // --- the trail ---------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                action TEXT NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id TEXT,
                tenant_id UUID REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID REFERENCES products (id) ON DELETE RESTRICT,
                user_id UUID REFERENCES users (id) ON DELETE RESTRICT,
                project_id UUID,
                request_id TEXT,
                detail JSONB NOT NULL DEFAULT '{}'::jsonb,
                CONSTRAINT audit_log_action_not_blank
                    CHECK (btrim(action) <> ''),
                CONSTRAINT audit_log_subject_type_not_blank
                    CHECK (btrim(subject_type) <> ''),
                CONSTRAINT audit_log_detail_is_object
                    CHECK (jsonb_typeof(detail) = 'object')
            )
            SQL);

        // ON DELETE RESTRICT on the actor is deliberate and will have to be
        // faced rather than worked around. An erasure request under RGPD and
        // a trail that names who acted are in genuine tension (#14, #15), and
        // the two easy answers are both wrong: CASCADE destroys the evidence
        // an audit exists to hold, SET NULL keeps the row while removing the
        // only thing it was recording. Refusing the delete forces M8's
        // retention service to decide out loud — anonymise, or retain under
        // a legal basis — instead of a foreign key deciding silently.

        $this->addSql(<<<'SQL'
            CREATE FUNCTION audit_log_is_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER audit_log_stays_written
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only()
            SQL);

        // The three questions the trail is read by: what happened to this
        // tenant, what happened in this request, and what happened lately.
        $this->addSql(<<<'SQL'
            CREATE INDEX audit_log_by_tenant ON audit_log (tenant_id, occurred_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX audit_log_by_request ON audit_log (request_id)
                WHERE request_id IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX audit_log_recent ON audit_log (occurred_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The trigger refuses row deletes, not the table's removal.
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP FUNCTION IF EXISTS audit_log_is_append_only()');

        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                   SELECT id FROM platform_permissions WHERE code LIKE 'admin.%'
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code LIKE 'admin.%'");

        $this->addSql(<<<'SQL'
            ALTER TABLE platform_permissions
                DROP CONSTRAINT platform_permissions_code_is_platform_scoped
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE platform_permissions
                ADD CONSTRAINT platform_permissions_code_is_staff_scoped
                    CHECK (code ~ '^(staff|support)\.')
            SQL);
    }
}
