<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The platform tells a product what happened (2026-09-21, ADR-051 milestone D).
 *
 * A product beside the platform holds an address where events are delivered
 * and a secret that signs them. The secret is sealed at rest with the
 * deployment's `WEBHOOK_SECRET_KEY` (a hash would not do: signing needs the
 * bytes), shown once at issue, and rotated with a window during which the
 * previous one still signs — so the product swaps its copy without a gap.
 *
 * `webhook_deliveries` is the outbox: one row per event, written by the act
 * that caused it and sent later by the queue. `event_id` is unique because
 * the product deduplicates by it, and a delivery that timed out after the
 * product wrote is a duplicate the product will see. `webhook_cursors` is
 * where the collector of subscription events keeps its place, so the
 * append-only `subscription_events` table is read forward once.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhook address and secret per product, the delivery outbox, and the collector cursor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD COLUMN webhook_url TEXT');
        $this->addSql('ALTER TABLE products ADD COLUMN webhook_secret TEXT');
        $this->addSql('ALTER TABLE products ADD COLUMN webhook_secret_issued_at TIMESTAMPTZ');
        $this->addSql('ALTER TABLE products ADD COLUMN webhook_previous_secret TEXT');
        $this->addSql('ALTER TABLE products ADD COLUMN webhook_previous_until TIMESTAMPTZ');

        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_deliveries (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                event_id UUID NOT NULL UNIQUE,
                event_type TEXT NOT NULL,
                tenant_id UUID REFERENCES tenants (id) ON DELETE SET NULL,
                payload JSONB NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                attempt INTEGER NOT NULL DEFAULT 0,
                next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                delivered_at TIMESTAMPTZ,
                parked_at TIMESTAMPTZ,
                last_status INTEGER,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT webhook_deliveries_payload_is_object CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL);
        // What the queue claims: due, undelivered, not given up on.
        $this->addSql(<<<'SQL'
            CREATE INDEX webhook_deliveries_due
                ON webhook_deliveries (next_attempt_at)
             WHERE delivered_at IS NULL AND parked_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX webhook_deliveries_product_time ON webhook_deliveries (product_id, created_at DESC)');

        // The collector reads the subscription history forward from its
        // cursor; the existing index serves one subscription's history, not
        // the platform's in time order.
        $this->addSql('CREATE INDEX subscription_events_in_order ON subscription_events (occurred_at, id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_cursors (
                name TEXT PRIMARY KEY,
                position TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS webhook_cursors');
        $this->addSql('DROP INDEX IF EXISTS subscription_events_in_order');
        $this->addSql('DROP TABLE IF EXISTS webhook_deliveries');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS webhook_previous_until');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS webhook_previous_secret');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS webhook_secret_issued_at');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS webhook_secret');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS webhook_url');
    }
}
