<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One live payment attempt per invoice, by index (2026-10-29).
 *
 * `Payments::start` checked only that the invoice was ISSUED and minted a new
 * attempt on every call, so two tabs — or a dunning step beside a page left
 * open — could authorize one invoice twice and collect it twice. The service
 * now abandons the live attempt at the provider before replacing it, and this
 * index is what makes "one at a time" a fact rather than a check two
 * simultaneous calls would both pass.
 *
 * **Older duplicates are closed first**, or the index could not be built: of
 * the live attempts an invoice already has, the newest stays live and the
 * others become CANCELLED with `SUPERSEDED` — the same words the service
 * writes when it replaces one, and a ledger row each so the move is not
 * silent. They are the attempts nobody could reach any more: each new start
 * handed its client secret to the page and forgot the previous one, and a
 * dunning attempt has no page at all.
 *
 * **Forward only.** Dropping the index would let one invoice be collected
 * twice again.
 */
final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One live payment attempt per invoice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            WITH ranked AS (
                SELECT id,
                       row_number() OVER (PARTITION BY invoice_id ORDER BY created_at DESC, id DESC) AS rank_in_invoice
                  FROM payments
                 WHERE status IN ('PENDING', 'AUTHORIZED')
            ),
            closed AS (
                UPDATE payments p
                   SET status = 'CANCELLED',
                       failed_at = now(),
                       failure_code = 'SUPERSEDED',
                       failure_reason = 'Abandoned for a newer attempt at the same invoice.',
                       updated_at = now()
                  FROM ranked r
                 WHERE r.id = p.id AND r.rank_in_invoice > 1
                RETURNING p.id, p.tenant_id, p.product_id, p.invoice_id, p.subscription_id,
                          p.amount_minor_units, p.currency
            )
            INSERT INTO financial_events
                (tenant_id, product_id, type, invoice_id, subscription_id, payment_id,
                 amount_minor_units, currency, actor_user_id, detail)
            SELECT tenant_id, product_id, 'PAYMENT_FAILED', invoice_id, subscription_id, id,
                   amount_minor_units, currency, NULL,
                   CAST('{"to": "CANCELLED", "superseded": true, "by": "migration"}' AS jsonb)
              FROM closed
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_live_attempt_per_invoice
                ON payments (invoice_id)
             WHERE status IN ('PENDING', 'AUTHORIZED')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Without the index one invoice can be collected twice.',
        );
    }
}
