<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoicing: who is billed, what they were billed, and the ledger of it.
 *
 * §25 states the rule this schema exists to enforce: "Une facture historique
 * ne doit pas dépendre des valeurs actuelles du plan." An invoice keeps its
 * own snapshot — customer, address, VAT, lines, prices, tax, currency — so
 * re-pricing an offer cannot retroactively change a document that has already
 * been issued to a customer and filed by their accountant.
 *
 * That is the whole reason the columns below duplicate data that also exists
 * in the catalogue. The duplication is the feature.
 */
final class Version20260903050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing profiles, invoices with snapshotted lines, tax records and the financial ledger';
    }

    public function up(Schema $schema): void
    {
        // Who the invoice is made out to, as it stands today. Invoices copy
        // from this at issue time rather than pointing at it, because a
        // customer moving office must not rewrite last year's invoices.
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_profiles (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                legal_name TEXT NOT NULL,
                vat_number TEXT,
                registration_number TEXT,
                address_line1 TEXT,
                address_line2 TEXT,
                postal_code TEXT,
                city TEXT,
                country_code TEXT,
                billing_email TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT billing_profiles_one_per_tenant UNIQUE (tenant_id),
                CONSTRAINT billing_profiles_legal_name_not_blank CHECK (btrim(legal_name) <> ''),
                CONSTRAINT billing_profiles_country_is_iso
                    CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')
            )
            SQL);

        // The document.
        //
        // number is nullable while DRAFT and required once ISSUED: a legal
        // invoice number is allocated at issue, not at creation, because an
        // abandoned draft must not consume one. French numbering has to be
        // sequential and gapless, which is why it is not a PostgreSQL
        // sequence — those skip numbers on rollback, and a skipped number is
        // a question from an auditor.
        //
        // The status set is §25.1's in full, including the states only the
        // e-invoicing adapter will reach. Listing them now means that
        // adapter does not need a migration to widen a constraint; which
        // transitions are legal is enforced in the domain, not here.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoices (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE SET NULL,
                number TEXT,
                status TEXT NOT NULL DEFAULT 'DRAFT',
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL DEFAULT 0,
                vat_minor_units BIGINT NOT NULL DEFAULT 0,
                gross_minor_units BIGINT NOT NULL DEFAULT 0,
                issued_at TIMESTAMPTZ,
                due_at TIMESTAMPTZ,
                paid_at TIMESTAMPTZ,
                period_start TIMESTAMPTZ,
                period_end TIMESTAMPTZ,
                payment_terms TEXT,
                credit_note_reference TEXT,
                supplier_snapshot JSONB NOT NULL DEFAULT '{}',
                customer_snapshot JSONB NOT NULL DEFAULT '{}',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT invoices_number_unique UNIQUE (number),
                CONSTRAINT invoices_status_known CHECK (status IN (
                    'DRAFT', 'ISSUED', 'READY_FOR_EINVOICE', 'SUBMITTED',
                    'ACCEPTED', 'REJECTED', 'PAID', 'CANCELLED', 'CREDITED'
                )),
                CONSTRAINT invoices_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT invoices_amounts_not_negative CHECK (
                    net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT invoices_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units),
                CONSTRAINT invoices_numbered_once_issued
                    CHECK (status = 'DRAFT' OR (number IS NOT NULL AND issued_at IS NOT NULL)),
                CONSTRAINT invoices_snapshots_are_objects CHECK (
                    jsonb_typeof(supplier_snapshot) = 'object'
                    AND jsonb_typeof(customer_snapshot) = 'object'
                ),
                CONSTRAINT invoices_period_ordered
                    CHECK (period_end IS NULL OR period_start IS NULL OR period_end > period_start)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX invoices_tenant_issued_idx
                ON invoices (tenant_id, product_id, issued_at DESC)
            SQL);

        // ON DELETE RESTRICT on tenant and product, deliberately: an invoice
        // outlives the account it was raised against. Legal accounting
        // retention is not the same obligation as RGPD deletion (§25.1), and
        // a cascade here would quietly resolve that tension in the wrong
        // direction.

        // Each line carries its own description and price. source_offer_version_id
        // records where the line came from, for lineage — nothing reads
        // amounts through it, and the test that re-versions an offer exists
        // to prove nothing ever will.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_lines (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                invoice_id UUID NOT NULL REFERENCES invoices (id) ON DELETE CASCADE,
                position INTEGER NOT NULL,
                description TEXT NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_price_minor_units BIGINT NOT NULL,
                discount_minor_units BIGINT NOT NULL DEFAULT 0,
                net_minor_units BIGINT NOT NULL,
                vat_rate_basis_points INTEGER NOT NULL DEFAULT 0,
                vat_minor_units BIGINT NOT NULL DEFAULT 0,
                gross_minor_units BIGINT NOT NULL,
                source_offer_version_id UUID REFERENCES offer_versions (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT invoice_lines_position_unique UNIQUE (invoice_id, position),
                CONSTRAINT invoice_lines_description_not_blank CHECK (btrim(description) <> ''),
                CONSTRAINT invoice_lines_quantity_positive CHECK (quantity > 0),
                CONSTRAINT invoice_lines_amounts_not_negative CHECK (
                    unit_price_minor_units >= 0 AND discount_minor_units >= 0
                    AND net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT invoice_lines_vat_rate_sane
                    CHECK (vat_rate_basis_points >= 0 AND vat_rate_basis_points <= 10000),
                CONSTRAINT invoice_lines_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units)
            )
            SQL);

        // VAT as it was applied, per rate, per invoice. Separate from the
        // lines because a return is filed per rate, not per line, and
        // recomputing it later from lines would be recomputing it from
        // rounding decisions nobody recorded.
        $this->addSql(<<<'SQL'
            CREATE TABLE tax_records (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                invoice_id UUID NOT NULL REFERENCES invoices (id) ON DELETE CASCADE,
                jurisdiction TEXT NOT NULL,
                rate_basis_points INTEGER NOT NULL,
                taxable_minor_units BIGINT NOT NULL,
                tax_minor_units BIGINT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT tax_records_unique_rate UNIQUE (invoice_id, jurisdiction, rate_basis_points),
                CONSTRAINT tax_records_jurisdiction_is_iso CHECK (jurisdiction ~ '^[A-Z]{2}$'),
                CONSTRAINT tax_records_rate_sane
                    CHECK (rate_basis_points >= 0 AND rate_basis_points <= 10000),
                CONSTRAINT tax_records_amounts_not_negative
                    CHECK (taxable_minor_units >= 0 AND tax_minor_units >= 0)
            )
            SQL);

        // The ledger. Append-only, like subscription_events and for the same
        // reason (#18) — but wider: this is where §25.2's financial reporting
        // reads from, and where the §20 chain quote → order → subscription →
        // invoice → payment becomes traceable in one place rather than by
        // joining five tables and hoping.
        $this->addSql(<<<'SQL'
            CREATE TABLE financial_events (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                type TEXT NOT NULL,
                invoice_id UUID REFERENCES invoices (id) ON DELETE SET NULL,
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE SET NULL,
                amount_minor_units BIGINT,
                currency TEXT,
                actor_user_id UUID REFERENCES users (id) ON DELETE SET NULL,
                detail JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT financial_events_type_known CHECK (type IN (
                    'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                    'INVOICE_CREDITED', 'INVOICE_PAID'
                )),
                CONSTRAINT financial_events_currency_is_iso
                    CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
                CONSTRAINT financial_events_amount_needs_currency
                    CHECK (amount_minor_units IS NULL OR currency IS NOT NULL),
                CONSTRAINT financial_events_detail_is_object CHECK (jsonb_typeof(detail) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX financial_events_ledger_idx
                ON financial_events (tenant_id, product_id, occurred_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('billing.read', 'Read the tenant''s billing profile and invoices'),
                ('billing.manage', 'Change the billing profile and issue invoices')
            SQL);

        // Everyone may read their own company's invoices; only an
        // administrator may change who the company is or raise a document.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'billing.read')
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'billing.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('billing.read', 'billing.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('billing.read', 'billing.manage')");
        $this->addSql('DROP TABLE IF EXISTS financial_events');
        $this->addSql('DROP TABLE IF EXISTS tax_records');
        $this->addSql('DROP TABLE IF EXISTS invoice_lines');
        $this->addSql('DROP TABLE IF EXISTS invoices');
        $this->addSql('DROP TABLE IF EXISTS billing_profiles');
    }
}
