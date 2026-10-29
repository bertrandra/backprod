<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A failed send is retried later, and a credential link does not outlive its
 * mail (2026-10-29).
 *
 * **`next_attempt_at`.** One refused SMTP connection set a delivery FAILED,
 * and FAILED is never claimed again: a password reset lost to a provider's
 * bad minute was lost for good. A failure with attempts left now returns the
 * delivery to PENDING with the time before which it may not be claimed;
 * NULL means "whenever", which is what every existing row means.
 *
 * **The credential link is scrubbed once used.** A password reset, an
 * invitation and an email confirmation carry their raw token in
 * `payload.link`, because the mail is rendered from the payload at send time.
 * Once the mail is sent, failed for good or suppressed, nothing renders it
 * again, and the token left behind is a credential at rest. The dispatcher
 * scrubs those after every pass; the partial index keeps that statement
 * reading only the few rows still carrying one, and this migration scrubs
 * the ones already spent before it ran.
 *
 * **Forward only**, like the rest: a scrubbed token cannot be put back.
 */
final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retry failed notification sends later, and scrub credential links once their mail is done';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_deliveries ADD COLUMN next_attempt_at timestamptz');

        $this->addSql(<<<'SQL'
            CREATE INDEX notifications_credential_link_idx
                ON notifications (id)
             WHERE type IN ('account.email_verification', 'account.password_reset', 'account.invitation')
               AND (payload -> 'link') IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE notifications
               SET payload = payload - 'link'
             WHERE type IN ('account.email_verification', 'account.password_reset', 'account.invitation')
               AND (payload -> 'link') IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM notification_deliveries email
                    WHERE email.notification_id = notifications.id
                      AND email.channel = 'EMAIL'
                      AND email.status IN ('SENT', 'DELIVERED', 'FAILED', 'SUPPRESSED')
               )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'A scrubbed credential link cannot be restored.',
        );
    }
}
