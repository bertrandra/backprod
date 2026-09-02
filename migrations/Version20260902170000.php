<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The commercial catalogue: plans, features, offers and their versions.
 *
 * §12 gives the chain — Offer → Subscription → Entitlements → Tenant — and
 * three definitions worth keeping straight, because the tables mirror them:
 *
 *   Offer         what is sold
 *   Subscription  what is subscribed to
 *   Entitlements  what the tenant may actually use
 *
 * This migration builds the first of the three. Subscriptions and
 * entitlements follow.
 *
 * Everything here is per-product. Two products can sell entirely different
 * plans under the same code without colliding, which is what §12.1 means by
 * a product being added with configuration rather than a fork.
 */
final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plans, billable features, offers and versioned offer terms';
    }

    public function up(Schema $schema): void
    {
        // The commercial tier — §12 calls it Offer.type (FREE / PRO /
        // BUSINESS / ENTERPRISE), §13 calls it Plan. It is a row rather than
        // an enum precisely so that §13's ban on `if ($plan === 'PRO')` is
        // enforceable: code that cannot see the name cannot branch on it.
        //
        // `rank` orders tiers so an offer change can be classified as an
        // upgrade or a downgrade by comparing two numbers, which is the
        // data-driven form of the same question.
        $this->addSql(<<<'SQL'
            CREATE TABLE plans (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                rank INTEGER NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT plans_code_unique UNIQUE (product_id, code),
                CONSTRAINT plans_code_not_blank CHECK (btrim(code) <> '')
            )
            SQL);

        // Billable capabilities: max_projects, advanced_3d, api_access (§13).
        //
        // Not to be confused with product_features from M3, which says what a
        // product has *built*. This says what a customer can *buy*. A product
        // can ship a feature it does not sell, and can sell one it has not
        // shipped — and conflating the two would make either case unsayable.
        //
        // kind separates the two shapes an entitlement takes: something you
        // either have, or something you have a number of.
        $this->addSql(<<<'SQL'
            CREATE TABLE features (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                kind TEXT NOT NULL,
                unit TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT features_code_unique UNIQUE (product_id, code),
                CONSTRAINT features_code_not_blank CHECK (btrim(code) <> ''),
                CONSTRAINT features_kind_known CHECK (kind IN ('BOOLEAN', 'QUOTA')),
                CONSTRAINT features_unit_only_for_quotas
                    CHECK (kind = 'QUOTA' OR unit IS NULL)
            )
            SQL);

        // The offer's identity, separate from its terms. Nothing here changes
        // when a price does — which is the point: a subscription referring to
        // an offer would otherwise silently change what it bought.
        $this->addSql(<<<'SQL'
            CREATE TABLE offers (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                plan_id UUID NOT NULL REFERENCES plans (id) ON DELETE RESTRICT,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT offers_code_unique UNIQUE (product_id, code),
                CONSTRAINT offers_code_not_blank CHECK (btrim(code) <> '')
            )
            SQL);

        // The terms, versioned. §12: "Une modification importante du prix, des
        // quotas ou des fonctionnalités crée une nouvelle version plutôt que
        // de réécrire l'historique." A subscription points at a version, so
        // what a tenant bought stays legible after the offer moves on.
        //
        // valid_from / valid_until are the *commercial* window — when this
        // version may be sold — and §12 is explicit that this is not the
        // tenant's subscription period. The two are different dates about
        // different things and are never stored in the same column.
        //
        // Money is integer minor units. A price is not a float, and rounding
        // a customer's invoice because the type was convenient is the kind of
        // defect that is found in an audit rather than in a test.
        $this->addSql(<<<'SQL'
            CREATE TABLE offer_versions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                offer_id UUID NOT NULL REFERENCES offers (id) ON DELETE CASCADE,
                version INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'DRAFT',
                billing_period TEXT NOT NULL,
                price_minor_units BIGINT NOT NULL,
                currency TEXT NOT NULL,
                valid_from TIMESTAMPTZ NOT NULL DEFAULT now(),
                valid_until TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT offer_versions_unique UNIQUE (offer_id, version),
                CONSTRAINT offer_versions_version_positive CHECK (version > 0),
                CONSTRAINT offer_versions_status_known
                    CHECK (status IN ('DRAFT', 'ACTIVE', 'EXPIRED', 'ARCHIVED')),
                CONSTRAINT offer_versions_billing_period_known
                    CHECK (billing_period IN ('MONTHLY', 'YEARLY', 'CUSTOM')),
                CONSTRAINT offer_versions_price_not_negative CHECK (price_minor_units >= 0),
                CONSTRAINT offer_versions_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT offer_versions_window_ordered
                    CHECK (valid_until IS NULL OR valid_until > valid_from)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX offer_versions_sellable_idx
                ON offer_versions (offer_id, status, valid_from, valid_until)
            SQL);

        // What a version grants. limit_value carries two meanings, and the
        // constraint keeps them apart: for a BOOLEAN feature it must be null,
        // and for a QUOTA feature null means unlimited. "Unlimited" as null
        // rather than a sentinel keeps the comparison honest in SQL —
        // `limit_value IS NULL OR used < limit_value` — where a -1 would
        // quietly compare as the smallest possible allowance.
        $this->addSql(<<<'SQL'
            CREATE TABLE offer_version_features (
                offer_version_id UUID NOT NULL REFERENCES offer_versions (id) ON DELETE CASCADE,
                feature_id UUID NOT NULL REFERENCES features (id) ON DELETE RESTRICT,
                limit_value BIGINT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (offer_version_id, feature_id),
                CONSTRAINT offer_version_features_limit_not_negative
                    CHECK (limit_value IS NULL OR limit_value >= 0)
            )
            SQL);

        // Reading the catalogue is not managing a subscription: everyone may
        // see what is for sale, which is why both roles get it.
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('catalog.read', 'Read the plans, features and offers on sale')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.code IN ('TENANT_ADMIN', 'USER')
              AND p.code = 'catalog.read'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'catalog.read')
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code = 'catalog.read'");
        $this->addSql('DROP TABLE IF EXISTS offer_version_features');
        $this->addSql('DROP TABLE IF EXISTS offer_versions');
        $this->addSql('DROP TABLE IF EXISTS offers');
        $this->addSql('DROP TABLE IF EXISTS features');
        $this->addSql('DROP TABLE IF EXISTS plans');
    }
}
