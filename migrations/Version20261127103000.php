<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An order with nothing to collect completes without a document (ADR-078).
 *
 * `orders_completed_is_traceable` required an invoice of every completed
 * order, so a free offer bought through the order chain had to raise a €0
 * invoice to be allowed to complete. That document was then a debt in every
 * reader's eyes: the checkout tried to collect it, nothing ever marked it
 * paid, and the collection pass suspended the free seat once the grace ran
 * out. A renewed free period has raised no document since 2026-10-29; the
 * first period now follows the same rule, and the constraint says what it
 * always meant — a completed sale is traceable to its document **when there
 * was something to collect**.
 *
 * **And the seats already suspended for nothing reopen.** A subscription in
 * arrears for an invoice of zero owes nothing, so it goes back to ACTIVE with
 * the event every other reopening writes. If it also owes a real invoice, the
 * next collection pass declares that one, in its own words.
 *
 * Forward only: tightening the constraint again would refuse the rows this
 * release writes.
 */
final class Version20261127103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A free order completes without an invoice, and no seat is in arrears for nothing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT orders_completed_is_traceable');
        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_completed_is_traceable CHECK (
                    status <> 'COMPLETED'
                    OR (
                        subscription_id IS NOT NULL
                        AND completed_at IS NOT NULL
                        AND (invoice_id IS NOT NULL OR gross_minor_units = 0)
                    )
                )
            SQL);

        $this->addSql(<<<'SQL'
            WITH reopened AS (
                UPDATE subscriptions s
                   SET status = 'ACTIVE',
                       past_due_since = NULL,
                       past_due_invoice_id = NULL,
                       updated_at = now()
                  FROM invoices i
                 WHERE i.id = s.past_due_invoice_id
                   AND i.gross_minor_units = 0
                   AND s.status = 'PAST_DUE'
                RETURNING s.id, i.id AS invoice_id
            )
            INSERT INTO subscription_events (subscription_id, type, detail)
            SELECT id, 'ARREARS_CLEARED',
                   jsonb_build_object('invoice_id', invoice_id, 'reason', 'NOTHING_WAS_OWED')
              FROM reopened
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Completed free orders carry no invoice; the older constraint would refuse them.',
        );
    }
}
