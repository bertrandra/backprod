<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A move to a lower plan waits for the period the customer paid for
 * (2026-09-27, spec §4).
 *
 * Until now `changeOffer` exchanged the entitlements immediately, whichever
 * direction the move went. A customer who had paid for Pro until the 31st
 * and chose Starter on the 3rd lost Pro on the 3rd: twenty-eight days of
 * service they had already bought, taken back because they said what they
 * wanted for next month. The specification calls that punitive and it is
 * simply wrong — the service is owed until the end of the paid period, which
 * is the same rule cancellation has obeyed since M5.
 *
 * So a downgrade writes an **intention** and nothing else:
 *
 * ```text
 * pending_offer_version_id   where it is going
 * pending_effective_at       when — always current_period_end
 * pending_requested_at       when it was asked for
 * pending_requested_by       who asked
 * ```
 *
 * **Columns and not a scheduling table.** There is at most one pending
 * change per subscription, and a table would allow several, which a
 * constraint would then have to forbid. One intention, one row, and the row
 * already exists.
 *
 * `pending_requested_by` is `ON DELETE SET NULL`, like every other actor
 * column here: a person leaving must not block the change they asked for,
 * and "who asked" is answerable from `subscription_events` regardless.
 *
 * **The two indicators are exclusive** (§2.2). A subscription that is
 * cancelling *and* dropping a plan at the same boundary has two endings
 * described, and only one of them can happen — so `subscriptions_one_ending`
 * refuses the pair in the database rather than in a service. Which one wins
 * is settled elsewhere and deliberately: scheduling a cancellation clears
 * any pending change, because the subscription is ending and there is
 * nothing left for it to become.
 *
 * The second CHECK is the shape §13.1 already uses for the commitment — a
 * derived column existing *exactly* when the state says it does. A pending
 * version with no date is a change nothing will ever apply; a date with no
 * version is a date nothing will ever act on. `num_nonnulls(...) IN (0, 3)`
 * says it once for the three columns that must agree. `pending_requested_by`
 * is not among them: a job may schedule a change, exactly as a job may
 * activate a subscription with no owner.
 */
final class Version20260927090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A change to a lower plan waits for the end of the paid period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD COLUMN pending_offer_version_id uuid REFERENCES offer_versions (id) ON DELETE RESTRICT,
                ADD COLUMN pending_effective_at timestamptz,
                ADD COLUMN pending_requested_at timestamptz,
                ADD COLUMN pending_requested_by uuid REFERENCES users (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD CONSTRAINT subscriptions_one_ending CHECK (
                    NOT (cancel_at_period_end AND pending_offer_version_id IS NOT NULL)
                ),
                ADD CONSTRAINT subscriptions_pending_is_dated CHECK (
                    num_nonnulls(
                        pending_offer_version_id,
                        pending_effective_at,
                        pending_requested_at
                    ) IN (0, 3)
                )
            SQL);

        // Asking for a change and withdrawing it are both things that
        // happened, and the history is the arbiter when a customer says "I
        // chose Starter" — the same reason a cancellation request is
        // recorded even when it is refused. The type set is closed, so it
        // widens here rather than being worked around with a detail field.
        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_events
                DROP CONSTRAINT subscription_events_type_known,
                ADD CONSTRAINT subscription_events_type_known CHECK (type IN (
                    'ACTIVATED', 'OFFER_CHANGED', 'CANCELLATION_SCHEDULED',
                    'CANCELLED', 'RESUMED', 'RENEWED', 'EXPIRED',
                    'CHANGE_SCHEDULED', 'CHANGE_CANCELLED'
                ))
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN subscriptions.pending_offer_version_id IS
                'The offer version this subscription moves to at the end of the period it has paid for (2026-09-27). Set by a move to a lower-ranked plan, which changes nothing until then; NULL means no change is waiting. Never set together with cancel_at_period_end — a subscription has one ending.'
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN subscriptions.pending_effective_at IS
                'When the pending change applies: current_period_end at the moment it was asked for, because that is what the customer has paid for.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping these columns would discard every change a customer has
        // asked for and not yet received — silently, and with the customer
        // still expecting the plan they chose on the date they were given.
        // Nothing reconstructs an intention from the rows that remain, and
        // narrowing the event types back would fail on every CHANGE_SCHEDULED
        // row already written.
        // ADR-016: a rollback in production means restoring a backup.
        $this->throwIrreversibleMigrationException(
            'subscriptions may already carry pending plan changes a customer was promised; restore a backup instead (ADR-016).',
        );
    }
}
