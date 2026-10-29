<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A refund can fail, and say which document it leaves unanswered
 * (2026-10-29).
 *
 * `refunds.status` has allowed FAILED since the first payment migration and
 * nothing ever wrote it: the provider's refusal was not modelled, so a refund
 * Stripe could not make stayed PENDING for ever, kept counting against what
 * was still refundable, and its credit note and negative VAT fact stayed
 * declared on money that never moved.
 *
 * **`refunds.credit_note_id`** names the credit note written with the refund.
 * A credit note is a numbered legal document and is never deleted, so when
 * its refund fails the document stays and a person decides what to do — give
 * the money back another way, or invoice the sale again. The link is what
 * lets them find it. RESTRICT, because a credit note cannot go and a refund
 * that pointed nowhere would hide the question.
 *
 * **`REFUND_FAILED`** joins both closed vocabularies: the webhook delivery
 * that says so, and the ledger row that records it with the document it
 * leaves unanswered.
 *
 * **Forward only.** Narrowing the vocabularies again would refuse rows
 * already written, and the column is the only record of which document a
 * failed refund carried.
 */
final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A refund can fail, and names the credit note it carried';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE refunds
                ADD COLUMN credit_note_id UUID REFERENCES credit_notes (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX refunds_credit_note_idx ON refunds (credit_note_id)
             WHERE credit_note_id IS NOT NULL
            SQL);

        $this->addSql('ALTER TABLE payment_events DROP CONSTRAINT payment_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_events ADD CONSTRAINT payment_events_type_known CHECK (type IN (
                'PAYMENT_AUTHORIZED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_CANCELLED', 'REFUND_SUCCEEDED', 'CHARGEBACK_OPENED',
                'INSTRUMENT_KNOWN', 'REFUND_FAILED'
            ))
            SQL);

        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED',
                'QUOTE_ACCEPTED', 'ORDER_PLACED', 'ORDER_COMPLETED', 'ORDER_CANCELLED',
                'ORDER_HELD', 'REFUND_FAILED'
            ))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'A failed refund and the credit note it carried are a record of money that did not move.',
        );
    }
}
