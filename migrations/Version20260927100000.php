<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An unpaid invoice suspends the workshop, and says so in its own words
 * (2026-09-27, spec §5).
 *
 * `PAST_DUE` did not exist anywhere before today — not in `src/`, not in
 * `migrations/` — so an invoice a customer never settled left the subscription
 * `ACTIVE` and the product wide open. The three statuses were `ACTIVE`,
 * `CANCELLED` and `EXPIRED`, and none of them means "owed for".
 *
 * **A status and not a flag**, for the reason §2.2 refuses a `CANCELED` status
 * that still grants rights: a status that does not describe the access is not a
 * status. `PAST_DUE` describes it exactly — the entitlements are suspended
 * (§5.1, decided 2026-09-26: suspended, not restricted) — so the first code to
 * read `status` and decide about access gets the right answer instead of a
 * plausible one.
 *
 * **`past_due_since` dates the entry**, and `past_due_invoice_id` names the
 * debt that caused it. Two columns because they answer two questions a screen
 * has to answer together: *since when* is the workshop shut, and *which
 * document* reopens it. Naming the invoice is also what keeps the recovery
 * honest — arrears is cleared by that invoice being paid, not by any payment
 * arriving from anywhere.
 *
 * The CHECK is one-way on purpose:
 *
 * ```sql
 * status <> 'PAST_DUE' OR past_due_since IS NOT NULL
 * ```
 *
 * A biconditional would read better and would be wrong: a subscription in
 * arrears that is then cancelled or reaches its term keeps the dates, because
 * "it was owed for when it died" is a fact worth keeping and nothing
 * reconstructs it afterwards. What must never happen is the other direction —
 * `PAST_DUE` with no date — which would make "since when?" unanswerable at the
 * only moment anybody asks.
 *
 * **Arrears is not an exit, so it does not free the scope.** The two partial
 * unique indexes of Version20260904060000 read `WHERE status = 'ACTIVE'`, and
 * adding a fourth status silently moved a subscription out from under them: a
 * customer owing for March could have bought a second subscription in April
 * and left the first unpaid for ever. So both widen to
 * `status IN ('ACTIVE', 'PAST_DUE')`. The rule they carry is unchanged — one
 * live subscription per scope — and what changed is that "live" now has two
 * spellings.
 *
 * `subscription_events` widens for the same reason it widened for a scheduled
 * change: the type set is closed, and "we suspended you on the 3rd and
 * reopened you on the 9th" has to be answerable from the history rather than
 * from a column that has since moved on.
 */
final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A subscription with an unpaid invoice is PAST_DUE, and PAST_DUE suspends its entitlements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD COLUMN past_due_since timestamptz,
                ADD COLUMN past_due_invoice_id uuid REFERENCES invoices (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                DROP CONSTRAINT subscriptions_status_known,
                ADD CONSTRAINT subscriptions_status_known
                    CHECK (status IN ('ACTIVE', 'PAST_DUE', 'CANCELLED', 'EXPIRED'))
            SQL);

        // `subscriptions_ended_when_not_active` read `status = 'ACTIVE' OR
        // ended_at IS NOT NULL`, which is the right rule badly spelled: what it
        // means is that a subscription which is *over* must say when, and it
        // said "not ACTIVE" because until today those were the same thing.
        // `PAST_DUE` is neither — it is running, suspended, and has no end date
        // because it has not ended — so the constraint names the two living
        // statuses rather than the one.
        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                DROP CONSTRAINT subscriptions_ended_when_not_active,
                ADD CONSTRAINT subscriptions_ended_when_not_active
                    CHECK (status IN ('ACTIVE', 'PAST_DUE') OR ended_at IS NOT NULL)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD CONSTRAINT subscriptions_past_due_is_dated CHECK (
                    status <> 'PAST_DUE' OR past_due_since IS NOT NULL
                ),
                ADD CONSTRAINT subscriptions_arrears_name_their_debt CHECK (
                    num_nonnulls(past_due_since, past_due_invoice_id) IN (0, 2)
                )
            SQL);

        // One live subscription per scope, and "live" now has two spellings.
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_tenant_subscription');
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_seat_per_person');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_tenant_subscription
                ON subscriptions (tenant_id, product_id)
             WHERE status IN ('ACTIVE', 'PAST_DUE') AND subscriber_kind = 'TENANT'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_seat_per_person
                ON subscriptions (tenant_id, product_id, subscriber_user_id)
             WHERE status IN ('ACTIVE', 'PAST_DUE') AND subscriber_kind = 'USER'
            SQL);

        // The collection pass reads this: which subscriptions are owed for, and
        // since when. Indexed rather than scanned because the pass runs from
        // cron over every tenant on the platform.
        $this->addSql(<<<'SQL'
            CREATE INDEX subscriptions_in_arrears_idx
                ON subscriptions (past_due_since)
             WHERE status = 'PAST_DUE'
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_events
                DROP CONSTRAINT subscription_events_type_known,
                ADD CONSTRAINT subscription_events_type_known CHECK (type IN (
                    'ACTIVATED', 'OFFER_CHANGED', 'CANCELLATION_SCHEDULED',
                    'CANCELLED', 'RESUMED', 'RENEWED', 'EXPIRED',
                    'CHANGE_SCHEDULED', 'CHANGE_CANCELLED',
                    'ARREARS_DECLARED', 'ARREARS_CLEARED'
                ))
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN subscriptions.past_due_since IS
                'When this subscription was declared in arrears (2026-09-27, spec §5.1). Set with the first chase the product configuration schedules, cleared when the invoice that caused it is paid. Kept after the subscription dies: it records that it was owed for, and nothing reconstructs that afterwards.'
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN subscriptions.past_due_invoice_id IS
                'Which unpaid invoice suspended it. The screen needs it to offer the way to pay, and recovery needs it so that arrears is cleared by *that* document settling rather than by any payment arriving.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping the status would have to decide what a suspended
        // subscription becomes, and both answers are wrong: `ACTIVE` reopens
        // every workshop somebody stopped paying for, and `EXPIRED` ends
        // contracts that are still running. Narrowing the event types would
        // also fail on every ARREARS_DECLARED row already written.
        // ADR-016: a rollback in production means restoring a backup.
        $this->throwIrreversibleMigrationException(
            'subscriptions may already be suspended for non-payment, and nothing here can decide what one becomes instead (restore a backup, ADR-016).',
        );
    }
}
