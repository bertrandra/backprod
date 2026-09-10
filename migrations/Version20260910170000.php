<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A deleted project keeps its history (R13).
 *
 * `DELETE FROM projects` removed the row, and `project_versions` followed
 * through `ON DELETE CASCADE`. A project with fifty snapshots left nothing
 * behind, and nothing said so until it was gone. The UI roadmap's U4 asked for
 * *"restoring a deleted project works, and the deleted state is visible"*,
 * which turned out to describe an API that did not exist — the frontend shipped
 * the honest thing instead (a delete that says the snapshots go with it,
 * permanently, with the project's name typed to confirm) and the gap was filed
 * as R13.
 *
 * **Deletion becomes a fact about the clock**, which is the same shape §15's
 * retention rules already use everywhere else in this platform: `erased_at` on
 * a user, `closed_at` on a VAT period, `deleted` on a message. The row stays,
 * its versions stay, and `deleted_at` says when somebody removed it.
 *
 * **Why this is safe against the cascade it replaces.** Nothing is dropped:
 * `project_versions`, `assets` and `jobs` keep pointing at a row that still
 * exists, so no foreign key changes and no orphan is created. The cascade stays
 * in place for the one deletion that is still real — a tenant being erased
 * takes its projects with it, and that is §15's business, not a person's
 * mistake at four in the afternoon.
 *
 * **The partial index is the point.** Every read of a live project filters on
 * `deleted_at IS NULL`, and a plain index would make the planner scan rows the
 * application has already decided are invisible. It also documents the rule:
 * the interesting set is the live one.
 *
 * What this does *not* decide is how long a deleted project is kept. That is a
 * retention policy with §15 on the other side of it, and belongs to whoever
 * writes the sweep — not to the migration that makes recovery possible.
 */
final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make project deletion recoverable: a deleted_at date rather than a cascade (R13).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD COLUMN deleted_at TIMESTAMPTZ');

        $this->addSql('ALTER TABLE projects ADD COLUMN deleted_by UUID REFERENCES users (id) ON DELETE SET NULL');

        // The set every live read wants, and the one the planner should be able
        // to reach without touching what has been removed.
        $this->addSql(<<<'SQL'
            CREATE INDEX projects_live_idx
                ON projects (tenant_id, product_id, updated_at DESC)
             WHERE deleted_at IS NULL
            SQL);

        // Who deleted it is only meaningful while it is deleted. A row carrying
        // an actor and no date would be a project somebody removed and that is
        // not removed, which is not a state this table has.
        $this->addSql(<<<'SQL'
            ALTER TABLE projects
                ADD CONSTRAINT projects_deleted_together
             CHECK ((deleted_at IS NULL) = (deleted_by IS NULL) OR (deleted_at IS NOT NULL AND deleted_by IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT projects_deleted_together');
        $this->addSql('DROP INDEX projects_live_idx');
        $this->addSql('ALTER TABLE projects DROP COLUMN deleted_by');
        $this->addSql('ALTER TABLE projects DROP COLUMN deleted_at');
    }
}
