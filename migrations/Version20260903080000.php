<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The two invariants a payment gate needs the database to hold.
 *
 * Until now an order was fulfilled on trust: the subscription started and the
 * invoice was raised in one act, and whether the money ever arrived was a
 * separate question nobody joined back up. AWAITING_PAYMENT has been a legal
 * value of `orders.status` since the table was created and nothing ever set
 * it. Now it does, which makes two things worth refusing in the schema rather
 * than only in the code that writes it.
 *
 * An order waiting for money must name the invoice it is waiting to be paid
 * for. Without that column filled there is nothing to pay, and nothing that
 * could ever release the order — an order stuck for a reason no query could
 * explain.
 *
 * And an invoice belongs to exactly one sale. The gate turns that from a
 * convention into a lookup: settlement finds the order to complete *by* the
 * invoice that was paid, so two orders sharing an invoice would make one
 * payment start two subscriptions. A partial unique index says so once, for
 * every writer, rather than each writer being careful.
 */
final class Version20260903080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment-gated order activation';
    }

    public function up(Schema $schema): void
    {
        // An order cannot wait for a payment it has raised no invoice for.
        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_awaiting_payment_has_invoice
                CHECK (status <> 'AWAITING_PAYMENT' OR invoice_id IS NOT NULL)
            SQL);

        // One invoice, one sale. Partial because most orders have no invoice
        // yet, and NULLs are not the thing being constrained.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX orders_invoice_unique
                ON orders (invoice_id)
             WHERE invoice_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS orders_invoice_unique');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_awaiting_payment_has_invoice');
    }
}
