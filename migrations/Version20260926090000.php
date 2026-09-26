<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A fiscal fact belongs to whoever owes the VAT (2026-09-26).
 *
 * `invoices` and `credit_notes` learned this on 2026-09-25 (ADR-054):
 * `issuer_tenant_id` names the organisation that raised the document, null
 * for the platform. `vat_transactions` did not, and the consequence was
 * found by reading the report query rather than a screen:
 *
 * ```sql
 * SELECT sum(vat_amount) FROM vat_transactions
 *  WHERE country = :jurisdiction AND transaction_date >= ...
 * ```
 *
 * No tenant filter, and none was needed while the platform was the only
 * supplier. Since an organisation sells seats to its own people (ADR-055),
 * that query sums **VAT other companies charged their own staff** into the
 * platform's own return. A French deployment with French customers would
 * have declared, and paid, tax that was never its to collect.
 *
 * So the column, and the filter that goes with it:
 *
 * ```text
 * issuer_tenant_id IS NULL      the platform's own VAT return
 * issuer_tenant_id = :tenant    that organisation's, read per tenant
 * ```
 *
 * **Every existing row is the platform's**, which is exactly what `NULL`
 * means, so nothing is backfilled and no declared figure moves. That matters
 * more here than anywhere: a closed period is immutable (§25.3), and a
 * migration that shifted one would be rewriting a filing.
 */
final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A VAT transaction names its issuer, so one return is not another\'s';
    }

    public function up(Schema $schema): void
    {
        // RESTRICT, like the same column on invoices: a company with
        // declarable facts behind it cannot be deleted out from under them.
        $this->addSql(<<<'SQL'
            ALTER TABLE vat_transactions
                ADD COLUMN issuer_tenant_id uuid REFERENCES tenants (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN vat_transactions.issuer_tenant_id IS
                'The organisation that charged this VAT and owes it. NULL means the platform did (2026-09-26); it matches the issuer_tenant_id of the document this fact came from.'
            SQL);

        // What the platform's own report reads. Partial on the platform's
        // rows, because that is the query that runs on every period: an
        // index over every organisation's facts too would be larger and
        // answer the same question no faster.
        $this->addSql(<<<'SQL'
            CREATE INDEX vat_transactions_platform_period_idx
                ON vat_transactions (country, transaction_date)
             WHERE issuer_tenant_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible, and the reverse is a *widening*: every organisation's
        // facts fall back into the platform's report, which is the state this
        // migration found. Nothing is destroyed and no fact is renumbered.
        $this->addSql('DROP INDEX IF EXISTS vat_transactions_platform_period_idx');
        $this->addSql('ALTER TABLE vat_transactions DROP COLUMN IF EXISTS issuer_tenant_id');
    }
}
