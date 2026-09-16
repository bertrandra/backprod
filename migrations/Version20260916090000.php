<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A webhook may say only what kind of instrument paid (ADR-048).
 *
 * The six event types `payment_events` accepted all move something: a
 * status, a refund, a chargeback. Stripe reports a payment's outcome and its
 * instrument in two deliveries — `payment_intent.succeeded` carries an
 * unexpanded `pm_…` id, `charge.succeeded` carries the kind — and the second
 * moves nothing. It still has to be *recorded*: exactly-once is the unique
 * index on `(provider, provider_event_id)`, and an event that is not written
 * is an event that can be applied twice. So the CHECK on `type` learns
 * `INSTRUMENT_KNOWN`. Recreated rather than widened in place, for the reason
 * Version20260903060000 gives: a CHECK cannot be extended, and dropping it
 * would let any string into a column humans read while investigating money.
 */
final class Version20260916090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment_events accepts INSTRUMENT_KNOWN, a delivery that names the instrument and moves nothing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_events DROP CONSTRAINT payment_events_type_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_events ADD CONSTRAINT payment_events_type_known CHECK (type IN (
                'PAYMENT_AUTHORIZED', 'PAYMENT_SUCCEEDED', 'PAYMENT_FAILED',
                'PAYMENT_CANCELLED', 'REFUND_SUCCEEDED', 'CHARGEBACK_OPENED',
                'INSTRUMENT_KNOWN'
            ))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Narrowing the CHECK back would fail on every INSTRUMENT_KNOWN row
        // already recorded, and deleting those rows would make the
        // deliveries they stand for look never-received — replayable.
        // ADR-016: a rollback in production means restoring a backup.
        $this->throwIrreversibleMigrationException(
            'payment_events may already hold INSTRUMENT_KNOWN deliveries; restore a backup instead (ADR-016).',
        );
    }
}
