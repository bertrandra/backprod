<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A gapless series belongs to whoever issues it (2026-09-25).
 *
 * Numbering was one series per document type per year, for the whole
 * platform — correct while the platform was the only issuer. Since an
 * organisation sells seats to its own people, it issues invoices under its
 * own legal identity, and three issuers sharing one counter produces
 * documents nobody can explain: the demonstration's Initech raised exactly
 * one invoice and it was numbered `2026-000005`, telling its customer about
 * four documents Initech never wrote. The other half is worse — Acme's
 * numbers ran 1, 2, 3 with 4 and 5 belonging to other companies, so Acme's
 * own series had gaps in it, which is the one thing gapless numbering exists
 * to prevent.
 *
 * So the series is the **issuer's**: `issuer_tenant_id` names the
 * organisation that issued the document, and `NULL` means the platform
 * issued it — which is every document raised before today, so existing rows
 * are already in the right series and nothing is renumbered. A legal number
 * never changes.
 *
 * **The platform keeps one series across its products**, deliberately. Its
 * supplier identity is configured per product, so two products *could* be
 * two legal entities; splitting on that guess would have retroactively cut
 * the platform's existing series into per-product pieces with gaps in each,
 * which is the defect being fixed, applied to the other issuer. If two
 * distinct legal entities are ever configured, that is a second change and
 * it has to bring a plan for the history.
 *
 * `NULLS NOT DISTINCT` is what makes the index guard the platform's series
 * at all: a plain unique index treats every `NULL` issuer as different, so
 * two platform invoices could both be `2026-000001` and the database would
 * say nothing. Partial on `number IS NOT NULL`, because a draft has no
 * number and any two drafts would otherwise collide on `(NULL, NULL)`.
 */
final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Number invoices and credit notes per issuer, not per platform';
    }

    public function up(Schema $schema): void
    {
        // RESTRICT, like `tenant_id` on the same tables: a company that has
        // issued invoices cannot be deleted out from under them (§26).
        foreach (['invoices', 'credit_notes'] as $table) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD COLUMN issuer_tenant_id uuid REFERENCES tenants (id) ON DELETE RESTRICT',
                $table,
            ));

            $this->addSql(sprintf(
                <<<'SQL'
                    COMMENT ON COLUMN %s.issuer_tenant_id IS
                        'The organisation that issued this document, and whose gapless series its number belongs to. NULL means the platform issued it (2026-09-25).'
                    SQL,
                $table,
            ));
        }

        $this->addSql('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_number_unique');
        $this->addSql('ALTER TABLE credit_notes DROP CONSTRAINT IF EXISTS credit_notes_number_unique');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX invoices_number_unique_per_issuer
                ON invoices (issuer_tenant_id, number) NULLS NOT DISTINCT
             WHERE number IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX credit_notes_number_unique_per_issuer
                ON credit_notes (issuer_tenant_id, number) NULLS NOT DISTINCT
            SQL);

        // What the numbering reads on every issue, and it reads it while
        // holding the table lock — so it is worth an index rather than a
        // scan of every document the platform has ever raised.
        $this->addSql('CREATE INDEX invoices_issuer_idx ON invoices (issuer_tenant_id)');
        $this->addSql('CREATE INDEX credit_notes_issuer_idx ON credit_notes (issuer_tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // Reversible, unusually for this repository: nothing is renumbered
        // and nothing is destroyed, so going back leaves every document
        // exactly as it was issued. What it would *not* survive is a
        // deployment where two organisations have both issued — their
        // numbers would collide on the restored global constraint, and the
        // migration would fail rather than merge two companies' series.
        $this->addSql('DROP INDEX IF EXISTS invoices_issuer_idx');
        $this->addSql('DROP INDEX IF EXISTS credit_notes_issuer_idx');
        $this->addSql('DROP INDEX IF EXISTS invoices_number_unique_per_issuer');
        $this->addSql('DROP INDEX IF EXISTS credit_notes_number_unique_per_issuer');

        $this->addSql('ALTER TABLE invoices DROP COLUMN IF EXISTS issuer_tenant_id');
        $this->addSql('ALTER TABLE credit_notes DROP COLUMN IF EXISTS issuer_tenant_id');

        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT invoices_number_unique UNIQUE (number)');
        $this->addSql('ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_number_unique UNIQUE (number)');
    }
}
