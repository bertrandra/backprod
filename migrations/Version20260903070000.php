<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The sales half of §20's chain, and the transmission records §25.1 requires.
 *
 * Quote → Order → Subscription/Purchase → Invoice → Payment. The right-hand
 * half exists; this is the left, plus the records that prove an invoice was
 * actually transmitted to an approved platform.
 *
 * Two decisions are visible in the shapes here.
 *
 * A quote has **no number**. French law requires invoices and credit notes to
 * be numbered in unbroken sequences; a devis is not subject to that, and
 * inventing a second numbering scheme with different rules is how one later
 * gets mistaken for the legal one.
 *
 * A quote **expires on the clock**, through `valid_until`, which is not
 * nullable. That is the rule this platform has applied since offers and
 * entitlements: a lapse is a fact about the clock, never about whether a job
 * has run. A quote with no expiry would be an open-ended price promise.
 */
final class Version20260903070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quotes, orders and e-invoice transmissions';
    }

    public function up(Schema $schema): void
    {
        // What was proposed to a customer, at a price, until a date.
        //
        // offer_version_id is RESTRICT for the same reason a subscription's
        // is: "what did we actually quote them?" must stay answerable.
        //
        // customer_snapshot is here for the same reason it is on an invoice.
        // A quote is a document that was *sent*; who it was addressed to is
        // part of what was sent, not a live lookup.
        $this->addSql(<<<'SQL'
            CREATE TABLE quotes (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                offer_version_id UUID NOT NULL REFERENCES offer_versions (id) ON DELETE RESTRICT,
                status TEXT NOT NULL DEFAULT 'DRAFT',
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL,
                vat_minor_units BIGINT NOT NULL,
                gross_minor_units BIGINT NOT NULL,
                valid_until TIMESTAMPTZ NOT NULL,
                customer_snapshot JSONB NOT NULL DEFAULT '{}',
                sent_at TIMESTAMPTZ,
                decided_at TIMESTAMPTZ,
                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT quotes_status_known CHECK (status IN (
                    'DRAFT', 'SENT', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'CANCELLED'
                )),
                CONSTRAINT quotes_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT quotes_amounts_not_negative CHECK (
                    net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT quotes_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units),
                CONSTRAINT quotes_decided_when_settled CHECK (
                    status NOT IN ('ACCEPTED', 'REJECTED') OR decided_at IS NOT NULL
                ),
                CONSTRAINT quotes_snapshot_is_object CHECK (jsonb_typeof(customer_snapshot) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX quotes_tenant_idx ON quotes (tenant_id, product_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quote_lines (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                quote_id UUID NOT NULL REFERENCES quotes (id) ON DELETE CASCADE,
                position INTEGER NOT NULL,
                description TEXT NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_price_minor_units BIGINT NOT NULL,
                discount_minor_units BIGINT NOT NULL DEFAULT 0,
                net_minor_units BIGINT NOT NULL,
                vat_rate_basis_points INTEGER NOT NULL DEFAULT 0,
                vat_minor_units BIGINT NOT NULL,
                gross_minor_units BIGINT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT quote_lines_position_unique UNIQUE (quote_id, position),
                CONSTRAINT quote_lines_description_not_blank CHECK (btrim(description) <> ''),
                CONSTRAINT quote_lines_quantity_positive CHECK (quantity > 0),
                CONSTRAINT quote_lines_amounts_not_negative CHECK (
                    unit_price_minor_units >= 0 AND discount_minor_units >= 0
                    AND net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT quote_lines_vat_rate_sane
                    CHECK (vat_rate_basis_points >= 0 AND vat_rate_basis_points <= 10000),
                CONSTRAINT quote_lines_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units)
            )
            SQL);

        // What the tenant actually committed to buy.
        //
        // quote_id is nullable because a direct purchase has no quote, and
        // RESTRICT because an order that came from one must keep pointing at
        // it: the quote is the terms the customer agreed to.
        //
        // subscription_id and invoice_id are filled when the order is
        // fulfilled, which is one transaction — an order marked COMPLETED
        // without either is a sale nobody can trace.
        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                quote_id UUID REFERENCES quotes (id) ON DELETE RESTRICT,
                offer_version_id UUID NOT NULL REFERENCES offer_versions (id) ON DELETE RESTRICT,
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE SET NULL,
                invoice_id UUID REFERENCES invoices (id) ON DELETE RESTRICT,
                status TEXT NOT NULL DEFAULT 'PENDING',
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL,
                vat_minor_units BIGINT NOT NULL,
                gross_minor_units BIGINT NOT NULL,
                placed_by UUID REFERENCES users (id) ON DELETE SET NULL,
                completed_at TIMESTAMPTZ,
                cancelled_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT orders_status_known CHECK (status IN (
                    'PENDING', 'AWAITING_PAYMENT', 'COMPLETED', 'CANCELLED'
                )),
                CONSTRAINT orders_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT orders_amounts_not_negative CHECK (
                    net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT orders_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units),
                CONSTRAINT orders_completed_is_traceable CHECK (
                    status <> 'COMPLETED'
                    OR (invoice_id IS NOT NULL AND subscription_id IS NOT NULL AND completed_at IS NOT NULL)
                ),
                CONSTRAINT orders_quote_used_once UNIQUE (quote_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX orders_tenant_idx ON orders (tenant_id, product_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_lines (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                order_id UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
                position INTEGER NOT NULL,
                description TEXT NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_price_minor_units BIGINT NOT NULL,
                discount_minor_units BIGINT NOT NULL DEFAULT 0,
                net_minor_units BIGINT NOT NULL,
                vat_rate_basis_points INTEGER NOT NULL DEFAULT 0,
                vat_minor_units BIGINT NOT NULL,
                gross_minor_units BIGINT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT order_lines_position_unique UNIQUE (order_id, position),
                CONSTRAINT order_lines_description_not_blank CHECK (btrim(description) <> ''),
                CONSTRAINT order_lines_quantity_positive CHECK (quantity > 0),
                CONSTRAINT order_lines_amounts_not_negative CHECK (
                    unit_price_minor_units >= 0 AND discount_minor_units >= 0
                    AND net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT order_lines_vat_rate_sane
                    CHECK (vat_rate_basis_points >= 0 AND vat_rate_basis_points <= 10000),
                CONSTRAINT order_lines_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units)
            )
            SQL);

        // Now that orders exist, a payment can say which sale it settled.
        $this->addSql(<<<'SQL'
            ALTER TABLE payments
                ADD COLUMN order_id UUID REFERENCES orders (id) ON DELETE RESTRICT
            SQL);

        // §25.1: keep the identifiers and transmission statuses the approved
        // platform supplies.
        //
        // One row per attempt to transmit an invoice, so a rejection followed
        // by a corrected resubmission leaves both visible — an inspector
        // asking "was this transmitted?" is owed the whole history, not the
        // latest answer.
        //
        // The platform is named rather than assumed, because non-negotiable
        // #17 requires the choice to stay interchangeable: a PDP is a
        // configured adapter, exactly like a payment provider.
        $this->addSql(<<<'SQL'
            CREATE TABLE einvoice_transmissions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                invoice_id UUID NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                provider TEXT NOT NULL,
                provider_document_id TEXT,
                status TEXT NOT NULL DEFAULT 'PENDING',
                rejection_code TEXT,
                rejection_reason TEXT,
                submitted_at TIMESTAMPTZ,
                settled_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT einvoice_transmissions_reference_unique UNIQUE (provider, provider_document_id),
                CONSTRAINT einvoice_transmissions_status_known CHECK (status IN (
                    'PENDING', 'SUBMITTED', 'ACCEPTED', 'REJECTED'
                )),
                CONSTRAINT einvoice_transmissions_submitted_has_reference
                    CHECK (status = 'PENDING' OR provider_document_id IS NOT NULL),
                CONSTRAINT einvoice_transmissions_rejected_has_reason
                    CHECK (status <> 'REJECTED' OR rejection_code IS NOT NULL)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX einvoice_transmissions_invoice_idx
                ON einvoice_transmissions (invoice_id, created_at DESC)
            SQL);

        // The platform's webhook deliveries, with the same unique index that
        // makes a payment webhook exactly-once.
        //
        // A separate table from payment_events rather than one polymorphic
        // log: the two have different subjects, and a single table could only
        // reference either by dropping the foreign key. A shared shape is
        // worth repeating; a lost referential guarantee is not.
        $this->addSql(<<<'SQL'
            CREATE TABLE einvoice_events (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                transmission_id UUID REFERENCES einvoice_transmissions (id) ON DELETE RESTRICT,
                provider TEXT NOT NULL,
                provider_event_id TEXT NOT NULL,
                provider_document_id TEXT NOT NULL,
                type TEXT NOT NULL,
                outcome TEXT NOT NULL,
                payload JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ,
                received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT einvoice_events_delivered_once UNIQUE (provider, provider_event_id),
                CONSTRAINT einvoice_events_type_known CHECK (type IN (
                    'EINVOICE_SUBMITTED', 'EINVOICE_ACCEPTED', 'EINVOICE_REJECTED'
                )),
                CONSTRAINT einvoice_events_outcome_known CHECK (outcome IN (
                    'APPLIED', 'IGNORED_STALE', 'IGNORED_UNKNOWN_TRANSMISSION', 'IGNORED_NOT_APPLICABLE'
                )),
                CONSTRAINT einvoice_events_applied_names_its_transmission
                    CHECK (outcome <> 'APPLIED' OR transmission_id IS NOT NULL),
                CONSTRAINT einvoice_events_payload_is_object CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX einvoice_events_transmission_idx
                ON einvoice_events (transmission_id, received_at DESC)
            SQL);

        // The ledger learns the sale.
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED',
                'QUOTE_ACCEPTED', 'ORDER_PLACED', 'ORDER_COMPLETED', 'ORDER_CANCELLED'
            ))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events
                ADD COLUMN order_id UUID REFERENCES orders (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('sales.read', 'Read the tenant''s quotes and orders'),
                ('sales.manage', 'Raise a quote, accept one, and place an order')
            SQL);

        // Everyone may see what their company was quoted and ordered; only an
        // administrator may commit to a purchase.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'sales.read')
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'sales.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('sales.read', 'sales.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('sales.read', 'sales.manage')");
        $this->addSql('ALTER TABLE financial_events DROP COLUMN IF EXISTS order_id');
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT IF EXISTS financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED'
            ))
            SQL);
        $this->addSql('DROP TABLE IF EXISTS einvoice_events');
        $this->addSql('DROP TABLE IF EXISTS einvoice_transmissions');
        $this->addSql('ALTER TABLE payments DROP COLUMN IF EXISTS order_id');
        $this->addSql('DROP TABLE IF EXISTS order_lines');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS quote_lines');
        $this->addSql('DROP TABLE IF EXISTS quotes');
    }
}
