<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Share links: a project shown to somebody with no account (2026-11-16,
 * ADR-073).
 *
 * **`project_shares`** holds one row per link. The token is never stored —
 * only its SHA-256, so what is stored cannot be presented — and the row
 * carries its own `(tenant, product)`, copied from the project and checked
 * against it by a composite foreign key: a link can only ever name a project
 * of the scope it says it belongs to, whatever the code that wrote it did.
 *
 * - `display_name` is what the reader is shown ("shared by …"), chosen by the
 *   sender; `recipient_label` is the sender's own note of who it was for, and
 *   is never shown to the reader.
 * - Expiry is mandatory and at most a year after creation, in the table and
 *   not only in the service.
 * - Revoking is a date. `open_count` and `last_opened_at` are all that is
 *   recorded about a reader: no address, no agent, nothing that identifies
 *   them.
 * - `ON DELETE CASCADE` from the project, like its versions: a project is
 *   soft-deleted (R13), so this only fires when its tenant or product goes.
 *
 * **`project_sharing`** joins the platform's list of features (ADR-052),
 * granted by nobody. Published offer versions are frozen (ADR-033), so
 * selling it is the operator's act: a new offer version, or a platform grant.
 *
 * **Forward only.** Dropping the table would end every link a customer has
 * handed out, with nothing to tell them it happened.
 */
final class Version20261116090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Share links for projects, and the project_sharing feature';
    }

    public function up(Schema $schema): void
    {
        // What the composite key below points at. `id` is already unique, so
        // this adds no constraint a row could break; it lets a child name the
        // scope it lives in and have the database hold it to that.
        $this->addSql(<<<'SQL'
            ALTER TABLE projects
                ADD CONSTRAINT projects_id_scope_unique UNIQUE (id, tenant_id, product_id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_shares (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id       UUID NOT NULL,
                product_id      UUID NOT NULL,
                project_id      UUID NOT NULL,
                token_hash      TEXT NOT NULL,
                display_name    TEXT,
                recipient_label TEXT,
                created_by      UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at      TIMESTAMPTZ NOT NULL,
                revoked_at      TIMESTAMPTZ,
                revoked_by      UUID REFERENCES users (id) ON DELETE SET NULL,
                open_count      INTEGER NOT NULL DEFAULT 0,
                last_opened_at  TIMESTAMPTZ,
                CONSTRAINT project_shares_project_in_scope
                    FOREIGN KEY (project_id, tenant_id, product_id)
                    REFERENCES projects (id, tenant_id, product_id) ON DELETE CASCADE,
                CONSTRAINT project_shares_token_hash_unique UNIQUE (token_hash),
                CONSTRAINT project_shares_token_hash_is_sha256
                    CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT project_shares_expiry_bounded
                    CHECK (expires_at > created_at AND expires_at <= created_at + interval '365 days'),
                CONSTRAINT project_shares_revoked_together
                    CHECK (revoked_at IS NOT NULL OR revoked_by IS NULL),
                CONSTRAINT project_shares_open_count_positive CHECK (open_count >= 0),
                CONSTRAINT project_shares_display_name_not_blank
                    CHECK (display_name IS NULL OR btrim(display_name) <> ''),
                CONSTRAINT project_shares_recipient_label_not_blank
                    CHECK (recipient_label IS NULL OR btrim(recipient_label) <> '')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX project_shares_project_idx
                ON project_shares (tenant_id, product_id, project_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX project_shares_created_by_idx ON project_shares (created_by)
             WHERE created_by IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO features (code, name, kind, description)
            VALUES ('project_sharing', 'Share links', 'BOOLEAN',
                    'Show a project, read-only, to somebody with no account.')
            ON CONFLICT (code) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO feature_translations (feature_id, locale, name, description)
            SELECT f.id, t.locale, t.name, t.description
              FROM features f
             CROSS JOIN (VALUES
                 ('fr', 'Liens de partage', 'Montrer un projet, en lecture seule, à quelqu’un qui n’a pas de compte.'),
                 ('es', 'Enlaces para compartir', 'Mostrar un proyecto, en solo lectura, a alguien sin cuenta.'),
                 ('de', 'Freigabelinks', 'Ein Projekt schreibgeschützt jemandem ohne Konto zeigen.'),
                 ('it', 'Link di condivisione', 'Mostrare un progetto, in sola lettura, a chi non ha un account.')
             ) AS t (locale, name, description)
             WHERE f.code = 'project_sharing'
            ON CONFLICT (feature_id, locale) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping share links would end every link a customer has handed out, without telling them.',
        );
    }
}
