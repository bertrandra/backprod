<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A paid order the platform could not release is a ledger fact.
 *
 * Found on the operator's own deployment on 2026-09-17: a tenant with a
 * live subscription bought a second offer on the same product, the card was
 * charged, and the webhook that brought the money hit the one-active-
 * subscription index — a 500 the provider retried for as long as it would,
 * with the payment PENDING throughout and the money collected throughout.
 * The checkout now refuses that sale before an invoice is raised; what is
 * left is the race — two orders opened before either was paid — and for
 * that the money is recorded and the order is *held*: invoice PAID, payment
 * SUCCEEDED, order still awaiting, and this row saying why, so the operator
 * refunds a customer rather than discovers one.
 */
final class Version20260917090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The ledger records an order held because its subscription could not start';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED',
                'QUOTE_ACCEPTED', 'ORDER_PLACED', 'ORDER_COMPLETED', 'ORDER_CANCELLED',
                'ORDER_HELD'
            ))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM financial_events WHERE type = 'ORDER_HELD'");
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED',
                'QUOTE_ACCEPTED', 'ORDER_PLACED', 'ORDER_COMPLETED', 'ORDER_CANCELLED'
            ))
            SQL);
    }
}
