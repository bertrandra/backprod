<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The three rollups the dashboard reads: turnover, offers, renewal (§25.2).
 *
 * §25.2 wants a dashboard resting on *données financières historisées*, and
 * the roadmap says what that rules out: recomputing over every transaction
 * each time somebody opens a page. So the answers are rolled up per month and
 * the dashboard reads rows, not the ledger.
 *
 * **Turnover is what was invoiced, not what was collected.** §25.2 asks
 * for revenue *and* for unpaid invoices and receivables, which only makes
 * sense if revenue is the billed figure — otherwise the two lines would be
 * the same fact twice. That is also the French reading of chiffre
 * d'affaires: recognised at issue.
 *
 * **Currency is part of the grain and totals are never summed across it.**
 * €100 and $100 are not €200, and a dashboard that adds them is worse than
 * one that declines to: the wrong number is actionable in a way a missing one
 * is not.
 *
 * **Counts are stored; rates are not.** `renewal_periods` holds how many came
 * up for renewal and how many renewed, and the rate is divided out on read.
 * A stored ratio is a second copy of a fact, and the two drift the first time
 * one is recomputed and the other is not.
 *
 * **A closed month cannot be restated.** Same one-way closure as
 * `vat_reporting_periods`: numbers somebody has already acted on do not
 * quietly change underneath them. The current month stays OPEN and is
 * recomputed; the dashboard says which it is.
 */
final class Version20260904080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monthly rollups for turnover, offer revenue and renewal';
    }

    public function up(Schema $schema): void
    {
        // The permission this dashboard is read behind. FINANCE_ADMIN and
        // SALES_ADMIN were defined in M6.2 with nothing to grant them; this
        // is the first thing that is theirs. PLATFORM_ADMIN gets it too, and
        // SUPPORT_ADMIN deliberately does not: support answers a customer's
        // question about their own account, which is not a reason to see
        // every customer's revenue.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.finance.read', 'Read the platform financial dashboard')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code = 'admin.finance.read'
               AND r.code IN ('PLATFORM_ADMIN', 'FINANCE_ADMIN', 'SALES_ADMIN')
            SQL);

        // --- turnover per month ------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE revenue_periods (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                period_start DATE NOT NULL,
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL DEFAULT 0,
                vat_minor_units BIGINT NOT NULL DEFAULT 0,
                gross_minor_units BIGINT NOT NULL DEFAULT 0,
                -- Credit notes are reported beside turnover, never netted
                -- into it. A single figure that quietly absorbed them would
                -- answer "what did we bill?" and "what do we keep?" with one
                -- number, and they are different questions with different
                -- audiences.
                credited_minor_units BIGINT NOT NULL DEFAULT 0,
                invoices_issued INTEGER NOT NULL DEFAULT 0,
                invoices_paid INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'OPEN',
                closed_at TIMESTAMPTZ,
                computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT revenue_periods_unique
                    UNIQUE (product_id, period_start, currency),
                CONSTRAINT revenue_periods_currency_is_iso
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT revenue_periods_status_known
                    CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT revenue_periods_closure_is_dated
                    CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL)),
                CONSTRAINT revenue_periods_starts_a_month
                    CHECK (period_start = date_trunc('month', CAST(period_start AS timestamp))::date),
                CONSTRAINT revenue_periods_amounts_not_negative
                    CHECK (net_minor_units >= 0 AND vat_minor_units >= 0
                           AND gross_minor_units >= 0 AND credited_minor_units >= 0),
                CONSTRAINT revenue_periods_counts_not_negative
                    CHECK (invoices_issued >= 0 AND invoices_paid >= 0),
                -- Paid is a subset of issued: an invoice cannot be settled in
                -- a month it was never raised in.
                CONSTRAINT revenue_periods_paid_within_issued
                    CHECK (invoices_paid <= invoices_issued)
            )
            SQL);

        // --- which offers earned it ---------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE offer_revenue_periods (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                offer_id UUID NOT NULL REFERENCES offers (id) ON DELETE RESTRICT,
                period_start DATE NOT NULL,
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL DEFAULT 0,
                lines_billed INTEGER NOT NULL DEFAULT 0,
                computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT offer_revenue_periods_unique
                    UNIQUE (product_id, offer_id, period_start, currency),
                CONSTRAINT offer_revenue_periods_currency_is_iso
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT offer_revenue_periods_starts_a_month
                    CHECK (period_start = date_trunc('month', CAST(period_start AS timestamp))::date),
                CONSTRAINT offer_revenue_periods_amounts_not_negative
                    CHECK (net_minor_units >= 0 AND lines_billed >= 0)
            )
            SQL);

        // Attribution runs through the *invoice line*, which snapshots the
        // offer version it was priced from (§25). Reading it back through the
        // subscription's current offer would credit today's offer with money
        // an older one earned, and re-credit it differently after an upgrade.

        // --- renewal, as counts ------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE renewal_periods (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                period_start DATE NOT NULL,
                due_count INTEGER NOT NULL DEFAULT 0,
                renewed_count INTEGER NOT NULL DEFAULT 0,
                ended_count INTEGER NOT NULL DEFAULT 0,
                computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT renewal_periods_unique
                    UNIQUE (product_id, period_start),
                CONSTRAINT renewal_periods_starts_a_month
                    CHECK (period_start = date_trunc('month', CAST(period_start AS timestamp))::date),
                CONSTRAINT renewal_periods_counts_not_negative
                    CHECK (due_count >= 0 AND renewed_count >= 0 AND ended_count >= 0),
                -- One constraint, not three. Given the counts cannot be
                -- negative, "renewed <= due" and "ended <= due" are both
                -- implied by this, and a constraint that can never be the one
                -- to fire is a line nobody will ever debug.
                CONSTRAINT renewal_periods_outcomes_within_due
                    CHECK (renewed_count + ended_count <= due_count)
            )
            SQL);

        // No rate column, deliberately: renewed_count / due_count is derived
        // on read and is *null* when nothing came up for renewal. Zero would
        // be a claim that everybody left, and right now nothing renews at all
        // — ExpireSubscriptions moves a finished period to EXPIRED and no job
        // calls renew() (R11). Until that exists the honest reading is "not
        // measurable", the same distinction UsageMeter draws between an
        // unmetered quota and one measured at zero.

        $this->addSql(<<<'SQL'
            CREATE FUNCTION revenue_period_closure_is_one_way() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'CLOSED' THEN
                    RAISE EXCEPTION
                        'revenue_period % is closed and cannot be restated', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER revenue_periods_stay_closed
                BEFORE UPDATE OR DELETE ON revenue_periods
                FOR EACH ROW EXECUTE FUNCTION revenue_period_closure_is_one_way()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX revenue_periods_evolution
                ON revenue_periods (product_id, currency, period_start)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX offer_revenue_periods_ranking
                ON offer_revenue_periods (product_id, period_start, currency, net_minor_units DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                   SELECT id FROM platform_permissions WHERE code = 'admin.finance.read'
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'admin.finance.read'");
        $this->addSql('DROP TABLE IF EXISTS renewal_periods');
        $this->addSql('DROP TABLE IF EXISTS offer_revenue_periods');
        $this->addSql('DROP TABLE IF EXISTS revenue_periods');
        $this->addSql('DROP FUNCTION IF EXISTS revenue_period_closure_is_one_way()');
    }
}
