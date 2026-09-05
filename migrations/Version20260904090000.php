<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A delivery can be claimed, so a second runner cannot send it again (R12).
 *
 * `UNIQUE (notification_id, channel)` already guaranteed there is only ever
 * one row per channel to send. What it could not guarantee is that only one
 * runner *acts* on that row: the claim left `status` at PENDING, and its row
 * locks were gone the moment the statement committed, so an overlapping cron
 * pass — the normal case on a polled queue, not an error — re-selected the
 * same rows and sent the same message twice. On a paid channel that is a
 * billed duplicate; on WhatsApp it risks the sender's standing.
 *
 * So a delivery now moves out of PENDING when it is claimed, exactly as a job
 * moves to RUNNING (ADR-027). The second pass's `WHERE status = 'PENDING'`
 * cannot see it, which is a fact about the row rather than a check two racing
 * copies would both pass.
 *
 * **The lease is what keeps that from trading a duplicate for a loss.** A
 * runner whose process dies mid-send would otherwise strand the delivery in
 * SENDING for ever, and a notification nobody sends is the failure a
 * pre-renewal notice cannot have. The lease expires, and an expired lease is
 * claimable again — the same reasoning the job queue applies, for the same
 * reason: a lapse is a fact about the clock, never about whether something
 * ran.
 */
final class Version20260904090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Claim notification deliveries under a lease so a retry cannot send twice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_deliveries ADD COLUMN leased_until timestamptz');

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            DROP CONSTRAINT notification_deliveries_status_known
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_status_known
                CHECK (status IN ('PENDING', 'SENDING', 'SENT', 'DELIVERED', 'FAILED', 'SUPPRESSED'))
            SQL);

        // The lease exists exactly while the delivery is claimed. A lease on a
        // sent row would say somebody still holds it, and a claimed row with
        // no lease is one nothing can ever take back.
        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_lease_matches_status
                CHECK ((status = 'SENDING') = (leased_until IS NOT NULL))
            SQL);

        // What a reclaim scans: the claimed rows whose holder is gone.
        $this->addSql(<<<'SQL'
            CREATE INDEX notification_deliveries_expired_idx
                ON notification_deliveries (leased_until)
             WHERE status = 'SENDING'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS notification_deliveries_expired_idx');

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            DROP CONSTRAINT IF EXISTS notification_deliveries_lease_matches_status
            SQL);

        // Anything mid-flight goes back to PENDING rather than being dropped:
        // the column is going away, and a row left claiming a lease it can no
        // longer hold would fail the narrowed check below.
        $this->addSql(<<<'SQL'
            UPDATE notification_deliveries
               SET status = 'PENDING', leased_until = NULL
             WHERE status = 'SENDING'
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            DROP CONSTRAINT notification_deliveries_status_known
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_status_known
                CHECK (status IN ('PENDING', 'SENT', 'DELIVERED', 'FAILED', 'SUPPRESSED'))
            SQL);

        $this->addSql('ALTER TABLE notification_deliveries DROP COLUMN leased_until');
    }
}
