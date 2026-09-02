<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Projects and their versions — the first product resource.
 *
 * The document itself is JSONB (§16): its shape belongs to the Core, and the
 * backend stores it without needing to know it. What the backend *does* need
 * to query — who owns it, what it is called, which schema it speaks — is
 * lifted into columns, so listing and filtering never reach inside the
 * document.
 *
 * Versions are full snapshots rather than deltas, which is exactly what §17
 * prescribes for the MVP: "chaque version peut être un snapshot JSONB
 * complet", with deltas held back until volume justifies them.
 */
final class Version20260902160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Projects with JSONB documents, full version snapshots, and project permissions';
    }

    public function up(Schema $schema): void
    {
        // tenant_id and product_id are both NOT NULL and both part of every
        // index: a project belongs to one tenant *of one product*, and the
        // pair is what every query filters on. Non-negotiable #8 is isolation
        // by tenant, and this is where it is enforced rather than remembered.
        $this->addSql(<<<'SQL'
            CREATE TABLE projects (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                description TEXT,
                schema_version INTEGER NOT NULL,
                document JSONB NOT NULL,
                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT projects_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT projects_schema_version_positive CHECK (schema_version > 0),
                CONSTRAINT projects_document_is_object CHECK (jsonb_typeof(document) = 'object')
            )
            SQL);

        // The listing order is (tenant, product) then most-recently-touched,
        // so the index matches the query rather than the table.
        $this->addSql(<<<'SQL'
            CREATE INDEX projects_tenant_product_updated_idx
                ON projects (tenant_id, product_id, updated_at DESC)
            SQL);

        // A full snapshot per version: every field a caller can change, as it
        // stood, so restoring needs nothing but this row. Copying the
        // schema_version matters most — a version written under schema 1 must
        // still say 1 after the project has moved to 2, or a restore would
        // silently mislabel an old document as a new one.
        $this->addSql(<<<'SQL'
            CREATE TABLE project_versions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                project_id UUID NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
                version_number INTEGER NOT NULL,
                label TEXT,
                name TEXT NOT NULL,
                description TEXT,
                schema_version INTEGER NOT NULL,
                document JSONB NOT NULL,
                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT project_versions_unique UNIQUE (project_id, version_number),
                CONSTRAINT project_versions_number_positive CHECK (version_number > 0),
                CONSTRAINT project_versions_document_is_object CHECK (jsonb_typeof(document) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX project_versions_project_number_idx
                ON project_versions (project_id, version_number DESC)
            SQL);

        // §11 names these projects.read / projects.write. They are added here
        // rather than in the M2 migration because that one granted every
        // existing permission to TENANT_ADMIN with a CROSS JOIN — a snapshot,
        // not a rule — so a permission introduced later must grant itself.
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('projects.read', 'List and read projects'),
                ('projects.write', 'Create, change, version and delete projects')
            SQL);

        // Both roles get both: creating projects is what a member of a
        // product like this one is for. Narrowing that is a role change in
        // the database, not a code change (§13).
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.code IN ('TENANT_ADMIN', 'USER')
              AND p.code IN ('projects.read', 'projects.write')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible in the sense that matters: nothing outside this migration
        // references these tables, and the permissions it granted go with the
        // rows that named them. It still destroys project data, which is why
        // ADR-016 says a rollback in production means restoring a backup.
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('projects.read', 'projects.write'))
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('projects.read', 'projects.write')");
        $this->addSql('DROP TABLE IF EXISTS project_versions');
        $this->addSql('DROP TABLE IF EXISTS projects');
    }
}
