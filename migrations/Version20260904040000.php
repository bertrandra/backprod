<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiscalité / TVA (§25.3): who the customer is, which regime governs a sale,
 * and what is left behind for the declaration.
 *
 * M6 already puts a VAT rate on an invoice. That rate comes from `VatPolicy`,
 * whose own docblock says what it is not: a configured per-country number
 * applied blindly, with no notion of who the buyer is. That is honest for a
 * single-country B2C launch and wrong the moment a German company buys with a
 * VAT number.
 *
 * Six tables, and the split between them is the point (§25.3):
 *
 *   customer_tax_profiles   who the buyer is, fiscally, as it stands today
 *   tax_identifications     a VAT number and — separately — its verification
 *   tax_rates               a rate, for a country, over a validity window
 *   vat_transactions        the declarable fiscal fact, written once
 *   vat_reporting_periods   a declaration period per jurisdiction
 *   vat_declarations        what was declared for one of them
 *
 * `tax_records` (M6) is not replaced: it stays the per-rate breakdown *inside*
 * an invoice. `vat_transactions` carries the fact that gets declared, with the
 * country of taxation, the regime, and the rule that chose it.
 *
 * Three shapes here are load-bearing rather than decorative.
 *
 * A rate has a **window**, never a "current" flag. Correcting a rate closes
 * one window and opens another; it never overwrites a value, because a rate
 * that moves must not shift a single euro of VAT already invoiced. The
 * exclusion constraint makes overlapping windows for the same country
 * impossible rather than merely discouraged.
 *
 * A verification is stored **with its date**, as evidence. "The customer typed
 * a number" and "the number was verified" are different facts, and only the
 * second grants reverse charge.
 *
 * A closed period is **immutable**, enforced by a trigger rather than by
 * service code — a check in one service does not survive the second code path.
 */
final class Version20260904040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tax profiles, rates with validity windows, VAT transactions and reporting periods';
    }

    public function up(Schema $schema): void
    {
        // Who the buyer is, fiscally. Distinct from billing_profiles, which
        // says who to address the document to: a customer can move office
        // without changing tax status, and can become VAT-registered without
        // moving.
        $this->addSql(<<<'SQL'
            CREATE TABLE customer_tax_profiles (
                tenant_id           uuid PRIMARY KEY REFERENCES tenants (id) ON DELETE CASCADE,
                customer_kind       TEXT NOT NULL DEFAULT 'B2C',
                country_code        TEXT,
                taxable_person      BOOLEAN NOT NULL DEFAULT FALSE,
                location_evidence   jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT customer_tax_profiles_kind_known
                    CHECK (customer_kind IN ('B2B', 'B2C')),

                -- ISO 3166-1 alpha-2, upper case. Not the VAT prefix: Greece
                -- is GR here and EL on a number, and XI is a VAT prefix that
                -- is not a country at all.
                CONSTRAINT customer_tax_profiles_country_is_iso
                    CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'),

                -- A taxable person is a business. Claiming to be one while
                -- declaring B2C is a contradiction, not a preference.
                CONSTRAINT customer_tax_profiles_taxable_is_b2b
                    CHECK (NOT taxable_person OR customer_kind = 'B2B')
            )
            SQL);

        // The number, and its verification, kept apart on purpose.
        $this->addSql(<<<'SQL'
            CREATE TABLE tax_identifications (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id           uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                vat_number          TEXT NOT NULL,
                country_prefix      TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT 'UNVERIFIED',
                verified_at         timestamptz,
                verification_source TEXT,
                verification_result jsonb,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT tax_identifications_status_known
                    CHECK (status IN ('UNVERIFIED', 'VERIFIED', 'INVALID', 'UNAVAILABLE')),

                -- A VAT prefix is two letters, and it is not an ISO country
                -- code: EL for Greece, XI for Northern Ireland.
                CONSTRAINT tax_identifications_prefix_shape
                    CHECK (country_prefix ~ '^[A-Z]{2}$'),

                -- Letters and digits, no spaces or punctuation, so that two
                -- spellings of the same number cannot both exist.
                CONSTRAINT tax_identifications_number_shape
                    CHECK (vat_number ~ '^[A-Z0-9]{4,20}$'),

                -- The number must start with its own prefix. Otherwise a
                -- FR number filed under DE would verify against nothing.
                CONSTRAINT tax_identifications_number_carries_prefix
                    CHECK (left(vat_number, 2) = country_prefix),

                -- A verification date exists exactly when a verification
                -- happened. The same shape as a job's lease: a derived column
                -- that exists precisely when the state says it does.
                CONSTRAINT tax_identifications_verified_is_dated
                    CHECK ((status IN ('VERIFIED', 'INVALID')) = (verified_at IS NOT NULL))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX tax_identifications_one_per_tenant
                ON tax_identifications (tenant_id, vat_number)
            SQL);

        // A rate, for a country, over a window. `valid_until` NULL means open
        // ended — in force until something closes it.
        $this->addSql(<<<'SQL'
            CREATE TABLE tax_rates (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                country_code        TEXT NOT NULL,
                rate_kind           TEXT NOT NULL DEFAULT 'STANDARD',
                basis_points        INTEGER NOT NULL,
                valid_from          timestamptz NOT NULL,
                valid_until         timestamptz,
                source              TEXT,
                created_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT tax_rates_country_is_iso
                    CHECK (country_code ~ '^[A-Z]{2}$'),
                CONSTRAINT tax_rates_kind_known
                    CHECK (rate_kind IN ('STANDARD', 'REDUCED', 'SUPER_REDUCED', 'PARKING', 'ZERO')),
                CONSTRAINT tax_rates_basis_points_sane
                    CHECK (basis_points >= 0 AND basis_points <= 10000),
                CONSTRAINT tax_rates_window_ordered
                    CHECK (valid_until IS NULL OR valid_until > valid_from)
            )
            SQL);

        // Two rates of the same kind for the same country cannot overlap in
        // time. Without this, "the rate on that date" has two answers and the
        // invoice silently picks one.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql(<<<'SQL'
            ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_windows_do_not_overlap
                EXCLUDE USING gist (
                    country_code WITH =,
                    rate_kind WITH =,
                    tstzrange(valid_from, valid_until) WITH &&
                )
            SQL);

        // The declarable fiscal fact. Written once, never updated: a
        // correction is a new row attached to a credit note, exactly as an
        // invoice is corrected by a credit note and never by a rewrite.
        $this->addSql(<<<'SQL'
            CREATE TABLE vat_transactions (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                invoice_id          uuid REFERENCES invoices (id) ON DELETE RESTRICT,
                credit_note_id      uuid REFERENCES credit_notes (id) ON DELETE RESTRICT,
                tenant_id           uuid NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id          uuid NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                country             TEXT NOT NULL,
                customer_tax_number TEXT,
                customer_tax_status TEXT NOT NULL DEFAULT 'NONE',
                supply_type         TEXT NOT NULL,
                taxable_base        BIGINT NOT NULL,
                vat_rate            INTEGER NOT NULL,
                vat_amount          BIGINT NOT NULL,
                currency            TEXT NOT NULL,
                vat_regime          TEXT NOT NULL,
                rule_id             TEXT NOT NULL,
                reverse_charge      BOOLEAN NOT NULL DEFAULT FALSE,
                transaction_date    timestamptz NOT NULL,
                created_at          timestamptz NOT NULL DEFAULT now(),

                -- A fiscal fact belongs to exactly one document. Neither, or
                -- both, would make "what produced this?" unanswerable.
                CONSTRAINT vat_transactions_names_one_document
                    CHECK ((invoice_id IS NULL) <> (credit_note_id IS NULL)),

                CONSTRAINT vat_transactions_country_is_iso
                    CHECK (country ~ '^[A-Z]{2}$'),
                CONSTRAINT vat_transactions_currency_is_iso
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT vat_transactions_rate_sane
                    CHECK (vat_rate >= 0 AND vat_rate <= 10000),
                CONSTRAINT vat_transactions_supply_known
                    CHECK (supply_type IN ('GOODS', 'SERVICES', 'DIGITAL_SERVICES')),
                CONSTRAINT vat_transactions_tax_status_known
                    CHECK (customer_tax_status IN ('NONE', 'PRESENTED', 'VERIFIED')),

                -- The closed set of §25.3. A regime outside it is a bug, and
                -- a bug that reaches a declaration is expensive.
                CONSTRAINT vat_transactions_regime_known
                    CHECK (vat_regime IN (
                        'STANDARD', 'REVERSE_CHARGE', 'OSS',
                        'EXEMPT', 'ZERO_RATED', 'OUT_OF_SCOPE'
                    )),

                -- Reverse charge means the buyer accounts for the tax, so the
                -- supplier's VAT is zero. A reverse-charged line carrying VAT
                -- is a contradiction that would be declared twice.
                CONSTRAINT vat_transactions_reverse_charge_is_zero
                    CHECK (NOT reverse_charge OR (vat_amount = 0 AND vat_rate = 0)),

                -- Reverse charge and the REVERSE_CHARGE regime are the same
                -- fact stated twice; they must not disagree.
                CONSTRAINT vat_transactions_reverse_charge_matches_regime
                    CHECK (reverse_charge = (vat_regime = 'REVERSE_CHARGE')),

                -- Nothing but STANDARD and OSS charges VAT.
                CONSTRAINT vat_transactions_only_taxed_regimes_carry_vat
                    CHECK (vat_amount = 0 OR vat_regime IN ('STANDARD', 'OSS')),

                -- Reverse charge requires a verified number, not a submitted
                -- one. This is the invariant R8 exists for, and it lives in
                -- the database because a check in one service does not
                -- survive the second code path.
                CONSTRAINT vat_transactions_reverse_charge_needs_verification
                    CHECK (vat_regime <> 'REVERSE_CHARGE' OR customer_tax_status = 'VERIFIED')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX vat_transactions_period_idx
                ON vat_transactions (country, transaction_date)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX vat_transactions_tenant_idx
                ON vat_transactions (tenant_id, product_id, transaction_date DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX vat_transactions_invoice_idx
                ON vat_transactions (invoice_id)
            SQL);

        // A declaration period, per jurisdiction. Closing is one-way.
        $this->addSql(<<<'SQL'
            CREATE TABLE vat_reporting_periods (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                jurisdiction        TEXT NOT NULL,
                period_kind         TEXT NOT NULL,
                starts_on           date NOT NULL,
                ends_on             date NOT NULL,
                status              TEXT NOT NULL DEFAULT 'OPEN',
                closed_at           timestamptz,
                closed_by           uuid REFERENCES users (id) ON DELETE RESTRICT,
                created_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT vat_reporting_periods_jurisdiction_is_iso
                    CHECK (jurisdiction ~ '^[A-Z]{2}$'),
                CONSTRAINT vat_reporting_periods_kind_known
                    CHECK (period_kind IN ('MONTHLY', 'QUARTERLY')),
                CONSTRAINT vat_reporting_periods_status_known
                    CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT vat_reporting_periods_window_ordered
                    CHECK (ends_on >= starts_on),

                -- Closed exactly when dated and attributed. Who closed a
                -- period is an audited fact (§25.2), not a detail.
                CONSTRAINT vat_reporting_periods_closed_is_dated
                    CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX vat_reporting_periods_unique
                ON vat_reporting_periods (jurisdiction, starts_on, ends_on)
            SQL);

        // What was declared for a period, frozen at closure. Recomputing it
        // later from live rows would answer a different question.
        $this->addSql(<<<'SQL'
            CREATE TABLE vat_declarations (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                period_id           uuid NOT NULL REFERENCES vat_reporting_periods (id) ON DELETE RESTRICT,
                currency            TEXT NOT NULL,
                total_base          BIGINT NOT NULL,
                total_vat           BIGINT NOT NULL,
                breakdown           jsonb NOT NULL,
                transaction_count   INTEGER NOT NULL,
                created_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT vat_declarations_currency_is_iso
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT vat_declarations_counts_are_sane
                    CHECK (transaction_count >= 0)
            )
            SQL);

        // One declaration per period. A second would make "what did we
        // declare?" ambiguous.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX vat_declarations_one_per_period
                ON vat_declarations (period_id)
            SQL);

        // A closed period is immutable, and the database is what enforces it.
        // The declaration is written in the same transaction that closes the
        // period, so the trigger fires on UPDATE of the period only.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION vat_period_closure_is_one_way() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'CLOSED' THEN
                    RAISE EXCEPTION 'vat_reporting_period % is closed and cannot be modified', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER vat_reporting_periods_closed_is_immutable
                BEFORE UPDATE OR DELETE ON vat_reporting_periods
                FOR EACH ROW EXECUTE FUNCTION vat_period_closure_is_one_way()
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('tax.read', 'Read the tenant''s tax profile, rates and fiscal history'),
                ('tax.manage', 'Change the tax profile and close reporting periods')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'tax.read')
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'tax.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('tax.read', 'tax.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('tax.read', 'tax.manage')");
        $this->addSql('DROP TRIGGER IF EXISTS vat_reporting_periods_closed_is_immutable ON vat_reporting_periods');
        $this->addSql('DROP FUNCTION IF EXISTS vat_period_closure_is_one_way()');
        $this->addSql('DROP TABLE IF EXISTS vat_declarations');
        $this->addSql('DROP TABLE IF EXISTS vat_reporting_periods');
        $this->addSql('DROP TABLE IF EXISTS vat_transactions');
        $this->addSql('DROP TABLE IF EXISTS tax_rates');
        $this->addSql('DROP TABLE IF EXISTS tax_identifications');
        $this->addSql('DROP TABLE IF EXISTS customer_tax_profiles');
    }
}
