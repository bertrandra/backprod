<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subscription commitment terms (§13.1).
 *
 * M5 gave a subscription a billing period and `cancel_at_period_end`. That is
 * the right answer for a month-to-month plan and the wrong one for a B2B deal:
 * nothing could express "24 months, billed monthly, no exit before month 12",
 * so nothing could refuse an exit at month two.
 *
 * The five durations §13.1 keeps apart, and the two rules that do not follow
 * from them:
 *
 *   billing_period       how often the customer pays        (already on the version)
 *   term_months          how long the subscription runs     NULL = open-ended
 *   commitment_months    how long it cannot be cancelled    0 = no commitment
 *   current_period_end   how long the service is owed for   (already here)
 *   notice_days          delay between request and effect
 *   cancellation_policy  what happens when they cancel
 *   renewal              what happens at the term
 *
 * The terms live on the offer version — it is what is sold — and are copied
 * into the subscription as **values** when it is taken out. Repricing or
 * re-terming an offer must not change one condition a customer already agreed
 * to; that is the invoice snapshot rule (§25) and the fiscal snapshot rule
 * (§25.3) applied to the contract.
 *
 * `commitment_months` is NOT NULL, and the constraint below is why rather than
 * for tidiness. On a nullable column `(NULL > 0) = (commitment_ends_at IS NOT
 * NULL)` evaluates to NULL, and a CHECK constraint *accepts* NULL — the rule
 * would pass exactly the row it exists to refuse. Zero means "no commitment";
 * absence means nothing.
 */
final class Version20260904060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscription term, commitment, cancellation policy and seat subscriptions';
    }

    public function up(Schema $schema): void
    {
        // --- what is sold -------------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE offer_versions
                ADD COLUMN term_months INTEGER,
                ADD COLUMN commitment_months INTEGER NOT NULL DEFAULT 0,
                ADD COLUMN cancellation_policy TEXT NOT NULL DEFAULT 'ANYTIME',
                ADD COLUMN renewal TEXT NOT NULL DEFAULT 'AUTO_RENEW',
                ADD COLUMN early_termination TEXT NOT NULL DEFAULT 'FORBIDDEN',
                ADD COLUMN notice_days INTEGER NOT NULL DEFAULT 0
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE offer_versions
                ADD CONSTRAINT offer_versions_term_positive
                    CHECK (term_months IS NULL OR term_months > 0),
                ADD CONSTRAINT offer_versions_commitment_not_negative
                    CHECK (commitment_months >= 0),
                ADD CONSTRAINT offer_versions_commitment_within_term
                    CHECK (term_months IS NULL OR commitment_months <= term_months),
                ADD CONSTRAINT offer_versions_notice_not_negative
                    CHECK (notice_days >= 0),
                ADD CONSTRAINT offer_versions_cancellation_policy_known
                    CHECK (cancellation_policy IN ('ANYTIME', 'AT_COMMITMENT_END', 'AT_TERM')),
                ADD CONSTRAINT offer_versions_renewal_known
                    CHECK (renewal IN ('AUTO_RENEW', 'ENDS_AT_TERM')),
                ADD CONSTRAINT offer_versions_early_termination_known
                    CHECK (early_termination IN ('FORBIDDEN', 'CHARGE_REMAINING', 'FREE'))
            SQL);

        // --- what was agreed to -------------------------------------------
        //
        // The same terms, snapshotted, plus who is bound by them and the dates
        // the clock will be asked about.
        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD COLUMN subscriber_kind TEXT NOT NULL DEFAULT 'TENANT',
                ADD COLUMN subscriber_user_id UUID REFERENCES users (id) ON DELETE RESTRICT,
                ADD COLUMN term_months INTEGER,
                ADD COLUMN term_ends_at TIMESTAMPTZ,
                ADD COLUMN commitment_months INTEGER NOT NULL DEFAULT 0,
                ADD COLUMN commitment_ends_at TIMESTAMPTZ,
                ADD COLUMN cancellation_policy TEXT NOT NULL DEFAULT 'ANYTIME',
                ADD COLUMN renewal TEXT NOT NULL DEFAULT 'AUTO_RENEW',
                ADD COLUMN early_termination TEXT NOT NULL DEFAULT 'FORBIDDEN',
                ADD COLUMN notice_days INTEGER NOT NULL DEFAULT 0,
                ADD COLUMN cancel_effective_at TIMESTAMPTZ
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD CONSTRAINT subscriptions_subscriber_kind_known
                    CHECK (subscriber_kind IN ('TENANT', 'USER')),

                -- A named subscriber exists exactly when the subscriber is a
                -- person. Either half alone is a row nobody can interpret: a
                -- seat entitling nobody, or a tenant subscription that also
                -- names someone.
                ADD CONSTRAINT subscriptions_named_subscriber
                    CHECK ((subscriber_kind = 'USER') = (subscriber_user_id IS NOT NULL)),

                ADD CONSTRAINT subscriptions_term_positive
                    CHECK (term_months IS NULL OR term_months > 0),
                ADD CONSTRAINT subscriptions_commitment_not_negative
                    CHECK (commitment_months >= 0),
                ADD CONSTRAINT subscriptions_commitment_within_term
                    CHECK (term_months IS NULL OR commitment_months <= term_months),

                -- A commitment date exists exactly when there is a commitment.
                -- The same shape as a job's lease: a derived column that
                -- exists precisely when the state says it does. Without it a
                -- subscription can carry an engagement nothing dates, and so
                -- nothing ever ends.
                ADD CONSTRAINT subscriptions_commitment_is_dated
                    CHECK ((commitment_months > 0) = (commitment_ends_at IS NOT NULL)),

                ADD CONSTRAINT subscriptions_term_after_start
                    CHECK (term_ends_at IS NULL OR term_ends_at > started_at),
                ADD CONSTRAINT subscriptions_notice_not_negative
                    CHECK (notice_days >= 0),
                ADD CONSTRAINT subscriptions_cancellation_policy_known
                    CHECK (cancellation_policy IN ('ANYTIME', 'AT_COMMITMENT_END', 'AT_TERM')),
                ADD CONSTRAINT subscriptions_renewal_known
                    CHECK (renewal IN ('AUTO_RENEW', 'ENDS_AT_TERM')),
                ADD CONSTRAINT subscriptions_early_termination_known
                    CHECK (early_termination IN ('FORBIDDEN', 'CHARGE_REMAINING', 'FREE')),

                -- A scheduled cancellation carries its effective date, and a
                -- date without a scheduled cancellation is a date nothing will
                -- act on. "I cancelled" / "we received nothing" needs an
                -- arbiter, and this is it.
                ADD CONSTRAINT subscriptions_cancellation_is_dated
                    CHECK (cancel_at_period_end = (cancel_effective_at IS NOT NULL))
            SQL);

        // --- one active subscription per scope, and the scope has two shapes
        //
        // M5's index said one per (tenant, product). With seats that rule
        // splits in two, and both halves stay indexes rather than checks: two
        // simultaneous subscriptions would each see "none yet" and both write.
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_per_product');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_tenant_subscription
                ON subscriptions (tenant_id, product_id)
             WHERE status = 'ACTIVE' AND subscriber_kind = 'TENANT'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_seat_per_person
                ON subscriptions (tenant_id, product_id, subscriber_user_id)
             WHERE status = 'ACTIVE' AND subscriber_kind = 'USER'
            SQL);

        // Entitlement resolution reads this on every request that asks what a
        // person may use, so the seat lookup is indexed rather than scanned.
        $this->addSql(<<<'SQL'
            CREATE INDEX subscriptions_seat_idx
                ON subscriptions (subscriber_user_id, product_id)
             WHERE subscriber_kind = 'USER'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS subscriptions_seat_idx');
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_seat_per_person');
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_tenant_subscription');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_per_product
                ON subscriptions (tenant_id, product_id)
             WHERE status = 'ACTIVE'
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                DROP CONSTRAINT IF EXISTS subscriptions_subscriber_kind_known,
                DROP CONSTRAINT IF EXISTS subscriptions_named_subscriber,
                DROP CONSTRAINT IF EXISTS subscriptions_term_positive,
                DROP CONSTRAINT IF EXISTS subscriptions_commitment_not_negative,
                DROP CONSTRAINT IF EXISTS subscriptions_commitment_within_term,
                DROP CONSTRAINT IF EXISTS subscriptions_commitment_is_dated,
                DROP CONSTRAINT IF EXISTS subscriptions_term_after_start,
                DROP CONSTRAINT IF EXISTS subscriptions_notice_not_negative,
                DROP CONSTRAINT IF EXISTS subscriptions_cancellation_policy_known,
                DROP CONSTRAINT IF EXISTS subscriptions_renewal_known,
                DROP CONSTRAINT IF EXISTS subscriptions_early_termination_known,
                DROP CONSTRAINT IF EXISTS subscriptions_cancellation_is_dated
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                DROP COLUMN IF EXISTS subscriber_kind,
                DROP COLUMN IF EXISTS subscriber_user_id,
                DROP COLUMN IF EXISTS term_months,
                DROP COLUMN IF EXISTS term_ends_at,
                DROP COLUMN IF EXISTS commitment_months,
                DROP COLUMN IF EXISTS commitment_ends_at,
                DROP COLUMN IF EXISTS cancellation_policy,
                DROP COLUMN IF EXISTS renewal,
                DROP COLUMN IF EXISTS early_termination,
                DROP COLUMN IF EXISTS notice_days,
                DROP COLUMN IF EXISTS cancel_effective_at
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE offer_versions
                DROP CONSTRAINT IF EXISTS offer_versions_term_positive,
                DROP CONSTRAINT IF EXISTS offer_versions_commitment_not_negative,
                DROP CONSTRAINT IF EXISTS offer_versions_commitment_within_term,
                DROP CONSTRAINT IF EXISTS offer_versions_notice_not_negative,
                DROP CONSTRAINT IF EXISTS offer_versions_cancellation_policy_known,
                DROP CONSTRAINT IF EXISTS offer_versions_renewal_known,
                DROP CONSTRAINT IF EXISTS offer_versions_early_termination_known
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE offer_versions
                DROP COLUMN IF EXISTS term_months,
                DROP COLUMN IF EXISTS commitment_months,
                DROP COLUMN IF EXISTS cancellation_policy,
                DROP COLUMN IF EXISTS renewal,
                DROP COLUMN IF EXISTS early_termination,
                DROP COLUMN IF EXISTS notice_days
            SQL);
    }
}
