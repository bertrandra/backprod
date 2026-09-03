<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subscriptions, their history, and the entitlements they produce.
 *
 * This closes §12's chain: Offer → Subscription → Entitlements → Tenant. The
 * catalogue said what is sold; this says what a tenant subscribed to, and
 * what they may consequently use.
 *
 * The through-line from the offer tables continues here: a lapse is a fact
 * about the clock, not about whether a job has run. A subscription's period
 * and an entitlement's window are both consulted against `now()`, so access
 * ends when it should even if nothing has swept the rows.
 */
final class Version20260903040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscriptions, subscription events and the entitlements they grant';
    }

    public function up(Schema $schema): void
    {
        // offer_version_id is ON DELETE RESTRICT, which makes the milestone's
        // exit criterion structural rather than a convention: an offer
        // version a tenant subscribed to cannot be deleted, so "what did they
        // agree to?" stays answerable (non-negotiable #18).
        //
        // current_period_end is nullable because a CUSTOM billing period has
        // no computable end. MONTHLY and YEARLY get one; a null means the
        // subscription runs until someone ends it, not that it has expired.
        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                offer_version_id UUID NOT NULL REFERENCES offer_versions (id) ON DELETE RESTRICT,
                status TEXT NOT NULL DEFAULT 'ACTIVE',
                started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                current_period_start TIMESTAMPTZ NOT NULL DEFAULT now(),
                current_period_end TIMESTAMPTZ,
                cancel_at_period_end BOOLEAN NOT NULL DEFAULT false,
                cancelled_at TIMESTAMPTZ,
                ended_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT subscriptions_status_known
                    CHECK (status IN ('ACTIVE', 'CANCELLED', 'EXPIRED')),
                CONSTRAINT subscriptions_period_ordered
                    CHECK (current_period_end IS NULL OR current_period_end > current_period_start),
                CONSTRAINT subscriptions_ended_when_not_active
                    CHECK (status = 'ACTIVE' OR ended_at IS NOT NULL)
            )
            SQL);

        // A tenant holds at most one live subscription per product. Partial,
        // so the cancelled and expired ones stay for history — which is the
        // whole point of keeping them.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_per_product
                ON subscriptions (tenant_id, product_id)
             WHERE status = 'ACTIVE'
            SQL);

        // Append-only. Non-negotiable #18 wants commercial history auditable,
        // and a row that can be updated is not history — nothing here is ever
        // rewritten, only added to.
        //
        // from_/to_offer_version_id are RESTRICT for the same reason as the
        // subscription's: an event that says "moved from this to that" is
        // worthless if either end can be deleted.
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_events (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                subscription_id UUID NOT NULL REFERENCES subscriptions (id) ON DELETE CASCADE,
                type TEXT NOT NULL,
                from_offer_version_id UUID REFERENCES offer_versions (id) ON DELETE RESTRICT,
                to_offer_version_id UUID REFERENCES offer_versions (id) ON DELETE RESTRICT,
                actor_user_id UUID REFERENCES users (id) ON DELETE SET NULL,
                detail JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT subscription_events_type_known CHECK (type IN (
                    'ACTIVATED', 'OFFER_CHANGED', 'CANCELLATION_SCHEDULED',
                    'CANCELLED', 'RESUMED', 'RENEWED', 'EXPIRED'
                )),
                CONSTRAINT subscription_events_detail_is_object CHECK (jsonb_typeof(detail) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX subscription_events_history_idx
                ON subscription_events (subscription_id, occurred_at DESC)
            SQL);

        // What a tenant may actually use.
        //
        // Derived from a subscription rather than queried through one on every
        // request: this table is read during the §10.6 chain, on every
        // authenticated call, and four joins into the catalogue is the wrong
        // hot path. Rewriting these rows is a single transaction whenever a
        // subscription changes.
        //
        // The window is what keeps that safe. An entitlement carries the
        // period it was granted for, so it lapses on the clock rather than
        // waiting for a sweep to notice — the same reasoning that decides
        // whether an offer may be sold.
        //
        // source distinguishes what a subscription produced from what a human
        // granted on top. A rewrite replaces the first and leaves the second,
        // so a negotiated exception is not silently undone by a plan change.
        $this->addSql(<<<'SQL'
            CREATE TABLE entitlements (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                feature_id UUID NOT NULL REFERENCES features (id) ON DELETE RESTRICT,
                limit_value BIGINT,
                source TEXT NOT NULL DEFAULT 'SUBSCRIPTION',
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE CASCADE,
                valid_from TIMESTAMPTZ NOT NULL DEFAULT now(),
                valid_until TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT entitlements_source_known CHECK (source IN ('SUBSCRIPTION', 'OVERRIDE')),
                CONSTRAINT entitlements_subscription_present_when_derived
                    CHECK (source <> 'SUBSCRIPTION' OR subscription_id IS NOT NULL),
                CONSTRAINT entitlements_limit_not_negative
                    CHECK (limit_value IS NULL OR limit_value >= 0),
                CONSTRAINT entitlements_window_ordered
                    CHECK (valid_until IS NULL OR valid_until > valid_from)
            )
            SQL);

        // The index the context chain reads on every authenticated request.
        $this->addSql(<<<'SQL'
            CREATE INDEX entitlements_live_idx
                ON entitlements (tenant_id, product_id, valid_from, valid_until)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('subscription.read', 'Read the tenant''s subscription'),
                ('subscription.manage', 'Subscribe, change offer, cancel and resume'),
                ('entitlements.read', 'Read what the tenant may use and how much of it')
            SQL);

        // Everyone may see what the tenant has and what it is using; only an
        // administrator may change what it pays for.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER')
                   AND p.code IN ('subscription.read', 'entitlements.read'))
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'subscription.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions
                WHERE code IN ('subscription.read', 'subscription.manage', 'entitlements.read')
            )
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM permissions
            WHERE code IN ('subscription.read', 'subscription.manage', 'entitlements.read')
            SQL);
        $this->addSql('DROP TABLE IF EXISTS entitlements');
        $this->addSql('DROP TABLE IF EXISTS subscription_events');
        $this->addSql('DROP TABLE IF EXISTS subscriptions');
    }
}
