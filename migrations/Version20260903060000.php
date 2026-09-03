<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payments, the webhook deliveries that drive them, refunds and credit notes.
 *
 * §24 puts the payment service provider outside this platform and makes the
 * server webhook the source of truth. Two consequences are structural here
 * rather than conventional.
 *
 * First, **no card data.** There is no column in this migration that could
 * hold a PAN, an expiry or a CVV, and `payments.method` is constrained to a
 * small set of labels so nobody can quietly start putting an instrument
 * there. What this platform stores is the provider's identifier for a
 * payment and what happened to it.
 *
 * Second, **a webhook cannot be applied twice.** `payment_events` is unique
 * on (provider, provider_event_id), and the event is recorded in the same
 * transaction that acts on it — so a replayed delivery is refused by the
 * index before it can activate anything a second time. That is the
 * milestone's exit criterion, made a property of the schema rather than of
 * remembering to check.
 */
final class Version20260903060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payments, webhook deliveries, refunds and credit notes';
    }

    public function up(Schema $schema): void
    {
        // An attempt to collect money for an invoice.
        //
        // invoice_id is RESTRICT: a payment that cannot say what it settled
        // is an unexplained movement of money, which is exactly what an
        // accountant asks about first.
        //
        // provider_payment_id is the provider's handle. It is what a webhook
        // arrives carrying, and what makes an event resolvable to a payment
        // without trusting anything else in the delivery.
        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                invoice_id UUID NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE SET NULL,
                provider TEXT NOT NULL,
                provider_payment_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                amount_minor_units BIGINT NOT NULL,
                currency TEXT NOT NULL,
                method TEXT,
                failure_code TEXT,
                failure_reason TEXT,
                succeeded_at TIMESTAMPTZ,
                failed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT payments_provider_reference_unique UNIQUE (provider, provider_payment_id),
                CONSTRAINT payments_status_known CHECK (status IN (
                    'PENDING', 'AUTHORIZED', 'SUCCEEDED', 'FAILED',
                    'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'CHARGEBACK'
                )),
                CONSTRAINT payments_amount_positive CHECK (amount_minor_units > 0),
                CONSTRAINT payments_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT payments_succeeded_has_moment
                    CHECK (status <> 'SUCCEEDED' OR succeeded_at IS NOT NULL),
                CONSTRAINT payments_failed_has_reason
                    CHECK (status <> 'FAILED' OR failure_code IS NOT NULL),
                CONSTRAINT payments_method_is_a_label CHECK (method IS NULL OR method IN (
                    'CARD', 'SEPA_DEBIT', 'TRANSFER', 'APPLE_PAY', 'GOOGLE_PAY', 'OTHER'
                ))
            )
            SQL);

        $this->addSql('CREATE INDEX payments_invoice_idx ON payments (invoice_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX payments_tenant_idx ON payments (tenant_id, product_id, created_at DESC)
            SQL);

        // Every webhook delivery, append-only.
        //
        // The unique index is the whole design. A provider retries until it
        // gets a 2xx, and networks duplicate; both are normal, and both must
        // be harmless. Recording the delivery inside the transaction that
        // applies it means the second attempt cannot get past the index to
        // act, so "exactly once" is enforced rather than attempted.
        //
        // payment_id is nullable on purpose: an event can arrive for a
        // payment this platform has not written yet — the provider redirected
        // the customer faster than our own request finished — and the honest
        // record of that is a row saying so, not a discarded delivery.
        //
        // payload holds the normalised event the adapter produced, never the
        // raw provider body: raw bodies carry fields nobody has vetted, and
        // this column is read by humans investigating money.
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_events (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                payment_id UUID REFERENCES payments (id) ON DELETE RESTRICT,
                provider TEXT NOT NULL,
                provider_event_id TEXT NOT NULL,
                provider_payment_id TEXT NOT NULL,
                type TEXT NOT NULL,
                outcome TEXT NOT NULL,
                payload JSONB NOT NULL DEFAULT '{}',
                occurred_at TIMESTAMPTZ,
                received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT payment_events_delivered_once UNIQUE (provider, provider_event_id),
                CONSTRAINT payment_events_type_known CHECK (type IN (
                    'PAYMENT_AUTHORIZED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                    'PAYMENT_CANCELLED', 'REFUND_SUCCEEDED', 'CHARGEBACK_OPENED'
                )),
                CONSTRAINT payment_events_outcome_known CHECK (outcome IN (
                    'APPLIED', 'IGNORED_STALE', 'IGNORED_UNKNOWN_PAYMENT', 'IGNORED_NOT_APPLICABLE'
                )),
                CONSTRAINT payment_events_applied_names_its_payment
                    CHECK (outcome <> 'APPLIED' OR payment_id IS NOT NULL),
                CONSTRAINT payment_events_payload_is_object CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX payment_events_payment_idx ON payment_events (payment_id, received_at DESC)
            SQL);

        // Money going back.
        //
        // A chargeback is a refund the customer's bank imposed rather than
        // one this platform granted, so it is a reason rather than a separate
        // table: the movement of money is identical and reporting on "what
        // did we pay back" should not have to union two tables to find out.
        $this->addSql(<<<'SQL'
            CREATE TABLE refunds (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                payment_id UUID NOT NULL REFERENCES payments (id) ON DELETE RESTRICT,
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                provider TEXT NOT NULL,
                provider_refund_id TEXT,
                amount_minor_units BIGINT NOT NULL,
                currency TEXT NOT NULL,
                reason TEXT NOT NULL DEFAULT 'REQUESTED',
                status TEXT NOT NULL DEFAULT 'PENDING',
                settled_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT refunds_provider_reference_unique UNIQUE (provider, provider_refund_id),
                CONSTRAINT refunds_amount_positive CHECK (amount_minor_units > 0),
                CONSTRAINT refunds_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT refunds_reason_known CHECK (reason IN (
                    'REQUESTED', 'DUPLICATE', 'FRAUDULENT', 'CHARGEBACK'
                )),
                CONSTRAINT refunds_status_known CHECK (status IN ('PENDING', 'SUCCEEDED', 'FAILED'))
            )
            SQL);

        $this->addSql('CREATE INDEX refunds_payment_idx ON refunds (payment_id)');

        // The credit note (avoir) that §25.1's CREDITED state has been
        // waiting for.
        //
        // It is a legal document in its own right, so it gets its own gapless
        // number in its own series — an invoice and a credit note sharing a
        // sequence would leave both with gaps. Same rule as invoices: the
        // number comes from max + 1 under a lock, never a sequence.
        //
        // invoice_id is RESTRICT because a credit note that cannot name what
        // it corrects is not a correction of anything.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_notes (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                invoice_id UUID NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
                number TEXT NOT NULL,
                reason TEXT,
                currency TEXT NOT NULL,
                net_minor_units BIGINT NOT NULL,
                vat_minor_units BIGINT NOT NULL,
                gross_minor_units BIGINT NOT NULL,
                issued_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                supplier_snapshot JSONB NOT NULL DEFAULT '{}',
                customer_snapshot JSONB NOT NULL DEFAULT '{}',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT credit_notes_number_unique UNIQUE (number),
                CONSTRAINT credit_notes_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT credit_notes_amounts_not_negative CHECK (
                    net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT credit_notes_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units),
                CONSTRAINT credit_notes_snapshots_are_objects CHECK (
                    jsonb_typeof(supplier_snapshot) = 'object'
                    AND jsonb_typeof(customer_snapshot) = 'object'
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX credit_notes_tenant_idx
                ON credit_notes (tenant_id, product_id, issued_at DESC)
            SQL);

        // Amounts are stored positive and the document's kind says which way
        // the money goes. A credit note whose lines were negative would make
        // every sum in every report depend on remembering to check a sign.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_note_lines (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                credit_note_id UUID NOT NULL REFERENCES credit_notes (id) ON DELETE CASCADE,
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
                CONSTRAINT credit_note_lines_position_unique UNIQUE (credit_note_id, position),
                CONSTRAINT credit_note_lines_description_not_blank CHECK (btrim(description) <> ''),
                CONSTRAINT credit_note_lines_quantity_positive CHECK (quantity > 0),
                CONSTRAINT credit_note_lines_amounts_not_negative CHECK (
                    unit_price_minor_units >= 0 AND discount_minor_units >= 0
                    AND net_minor_units >= 0 AND vat_minor_units >= 0 AND gross_minor_units >= 0
                ),
                CONSTRAINT credit_note_lines_vat_rate_sane
                    CHECK (vat_rate_basis_points >= 0 AND vat_rate_basis_points <= 10000),
                CONSTRAINT credit_note_lines_gross_is_net_plus_vat
                    CHECK (gross_minor_units = net_minor_units + vat_minor_units)
            )
            SQL);

        // The ledger learns the rest of the §20 chain. Recreated rather than
        // widened in place because a CHECK cannot be extended, and the
        // alternative — dropping the constraint and leaving it off — would
        // let any string into the column the financial reports group by.
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID',
                'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'CREDIT_NOTE_ISSUED'
            ))
            SQL);

        // The ledger can now point at a payment, so a movement of money is
        // traceable to the document that justified it without a join through
        // three tables.
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events
                ADD COLUMN payment_id UUID REFERENCES payments (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('payments.read', 'Read the tenant''s payments and refunds'),
                ('payments.manage', 'Start a payment, refund one, and issue a credit note')
            SQL);

        // Everyone may see what their company paid; only an administrator may
        // move money.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'payments.read')
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'payments.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('payments.read', 'payments.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('payments.read', 'payments.manage')");
        $this->addSql('ALTER TABLE financial_events DROP COLUMN IF EXISTS payment_id');
        $this->addSql('ALTER TABLE financial_events DROP CONSTRAINT IF EXISTS financial_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_known CHECK (type IN (
                'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_CANCELLED',
                'INVOICE_CREDITED', 'INVOICE_PAID'
            ))
            SQL);
        $this->addSql('DROP TABLE IF EXISTS credit_note_lines');
        $this->addSql('DROP TABLE IF EXISTS credit_notes');
        $this->addSql('DROP TABLE IF EXISTS refunds');
        $this->addSql('DROP TABLE IF EXISTS payment_events');
        $this->addSql('DROP TABLE IF EXISTS payments');
    }
}
