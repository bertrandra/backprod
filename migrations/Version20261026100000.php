<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiscal records are written once, by the database (2026-10-26).
 *
 * "Written once" and "a closed period is immutable" held for the declaration
 * row and the stored PDFs, which have triggers, and for nothing else: nothing
 * stopped an UPDATE or a DELETE on the VAT facts, the credit notes, the
 * invoice lines, or the amounts and number of an issued invoice — only the
 * discipline of every statement anybody would ever write. The same reason the
 * PDFs and the period closure live in triggers applies here.
 *
 *   - `vat_transactions`, `invoice_lines`, `credit_notes`, `credit_note_lines`:
 *     no UPDATE, no DELETE. A correction is a new row — a reversal, a credit
 *     note — never an edit.
 *   - `invoices`: once out of DRAFT, what the document says is frozen — its
 *     number, parties, amounts, currency, dates and terms. Its status, the
 *     moment it was paid and the subscription it releases still move; that is
 *     the invoice's life, not a rewrite of it. An issued invoice is never
 *     deleted.
 *   - A fact dated inside a closed period of the same issuer and country is
 *     refused: it would be in no declaration. A correction belongs in a later
 *     period (§25.3), dated the day it is made.
 *
 * The test database's reset disables these for its own transaction, as it
 * already does for the triggers before them; the demonstration's wipe uses
 * TRUNCATE, which row triggers do not see.
 */
final class Version20261026100000 extends AbstractMigration
{
    private const WRITTEN_ONCE = ['vat_transactions', 'invoice_lines', 'credit_notes', 'credit_note_lines'];

    public function getDescription(): string
    {
        return 'fiscal records are written once: VAT facts, invoice lines, credit notes, and an issued invoice\'s content; no fact in a closed period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION fiscal_record_is_written_once() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is a fiscal record and is written once; a correction is a new row', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (self::WRITTEN_ONCE as $table) {
            $this->addSql(sprintf(
                'CREATE TRIGGER %1$s_written_once BEFORE UPDATE OR DELETE ON %1$s'
                . ' FOR EACH ROW EXECUTE FUNCTION fiscal_record_is_written_once()',
                $table,
            ));
        }

        $this->addSql(<<<'SQL'
            CREATE FUNCTION issued_invoice_is_frozen() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'DRAFT' THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'invoice % has been issued and is never deleted', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.product_id IS DISTINCT FROM OLD.product_id
                    OR NEW.issuer_tenant_id IS DISTINCT FROM OLD.issuer_tenant_id
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.net_minor_units IS DISTINCT FROM OLD.net_minor_units
                    OR NEW.vat_minor_units IS DISTINCT FROM OLD.vat_minor_units
                    OR NEW.gross_minor_units IS DISTINCT FROM OLD.gross_minor_units
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.due_at IS DISTINCT FROM OLD.due_at
                    OR NEW.period_start IS DISTINCT FROM OLD.period_start
                    OR NEW.period_end IS DISTINCT FROM OLD.period_end
                    OR NEW.payment_terms IS DISTINCT FROM OLD.payment_terms
                    OR NEW.credit_note_reference IS DISTINCT FROM OLD.credit_note_reference
                    OR NEW.supplier_snapshot IS DISTINCT FROM OLD.supplier_snapshot
                    OR NEW.customer_snapshot IS DISTINCT FROM OLD.customer_snapshot
                    OR NEW.locale IS DISTINCT FROM OLD.locale
                THEN
                    RAISE EXCEPTION 'invoice % has been issued; what it says is corrected by a credit note, never edited', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER invoices_issued_is_frozen BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION issued_invoice_is_frozen()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION vat_fact_not_in_a_closed_period() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                      FROM vat_reporting_periods p
                     WHERE p.status = 'CLOSED'
                       AND p.jurisdiction = NEW.country
                       AND p.issuer_tenant_id IS NOT DISTINCT FROM NEW.issuer_tenant_id
                       AND NEW.transaction_date >= p.starts_on
                       AND NEW.transaction_date < p.ends_on + 1
                ) THEN
                    RAISE EXCEPTION 'a VAT fact dated % falls in a closed period; a correction belongs in a later one', NEW.transaction_date
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER vat_transactions_not_in_a_closed_period BEFORE INSERT ON vat_transactions
                FOR EACH ROW EXECUTE FUNCTION vat_fact_not_in_a_closed_period()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS vat_transactions_not_in_a_closed_period ON vat_transactions');
        $this->addSql('DROP FUNCTION IF EXISTS vat_fact_not_in_a_closed_period()');
        $this->addSql('DROP TRIGGER IF EXISTS invoices_issued_is_frozen ON invoices');
        $this->addSql('DROP FUNCTION IF EXISTS issued_invoice_is_frozen()');

        foreach (self::WRITTEN_ONCE as $table) {
            $this->addSql(sprintf('DROP TRIGGER IF EXISTS %1$s_written_once ON %1$s', $table));
        }

        $this->addSql('DROP FUNCTION IF EXISTS fiscal_record_is_written_once()');
    }
}
