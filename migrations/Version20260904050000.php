<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications (§27.1): one event, several channels, none in the request
 * path.
 *
 * Four tables, and the split is the point:
 *
 *   notifications             the intent to inform somebody
 *   notification_deliveries   one attempt on one channel, with its own outcome
 *   notification_preferences  what a person wants, per category and channel
 *   notification_consents     opt-in for SMS and WhatsApp, with its proof
 *
 * A notification is not a message (§12.3). A conversation has participants, an
 * order and a reply; a notification is one-way. Mixing them would put system
 * noise in support threads and give a human message the fate of a mutable
 * preference — so they are separate tables with no foreign key between them.
 *
 * Three shapes here carry weight.
 *
 * `UNIQUE (notification_id, channel)` is what makes delivery exactly-once.
 * ADR-027 requires idempotent handlers because an expired lease lets two
 * copies of a job finish; for a notification a duplicate is a *billed* SMS
 * and an annoyed recipient. A check would be raced; an index cannot be.
 *
 * A suppression records **why**, because "we did not send it" and "we never
 * tried" are different answers to "did we tell them?" — which is exactly the
 * question a pre-renewal notice (§13.1) has to be able to settle.
 *
 * `rendered_body` is kept only where the notification has legal effect. The
 * payload is data and the text is rendered at send time, for the recipient's
 * language and the channel's format; but what can later be relied on must be
 * re-readable as it was sent, for the reason an invoice keeps its snapshot.
 */
final class Version20260904050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications, deliveries, preferences and consents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id           uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id          uuid NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                recipient_user_id   uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                type                TEXT NOT NULL,
                category            TEXT NOT NULL,
                payload             jsonb NOT NULL DEFAULT '{}'::jsonb,
                dedup_key           TEXT,
                legal_effect        BOOLEAN NOT NULL DEFAULT FALSE,
                created_at          timestamptz NOT NULL DEFAULT now(),
                read_at             timestamptz,

                CONSTRAINT notifications_category_known
                    CHECK (category IN ('BILLING', 'ACCOUNT', 'SECURITY', 'SUPPORT', 'MARKETING')),

                -- A dotted event name, so the type reads as what happened
                -- rather than as a label somebody invented at the call site.
                CONSTRAINT notifications_type_shape
                    CHECK (type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$')
            )
            SQL);

        // The screen channel reads this: the recipient's own unread list,
        // newest first.
        $this->addSql(<<<'SQL'
            CREATE INDEX notifications_recipient_idx
                ON notifications (recipient_user_id, product_id, created_at DESC)
            SQL);

        // A burst of the same event must produce one notice, not three. The
        // window is the caller's business; making the key unique is not,
        // because two runners racing would each see "no recent one" and both
        // write. Partial, so a notification with no key is never deduplicated.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX notifications_dedup_unique
                ON notifications (recipient_user_id, product_id, type, dedup_key)
             WHERE dedup_key IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_deliveries (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                notification_id     uuid NOT NULL REFERENCES notifications (id) ON DELETE CASCADE,
                channel             TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT 'PENDING',
                suppression_reason  TEXT,
                provider_message_id TEXT,
                attempts            INTEGER NOT NULL DEFAULT 0,
                failure_reason      TEXT,
                rendered_body       TEXT,
                sent_at             timestamptz,
                delivered_at        timestamptz,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT notification_deliveries_channel_known
                    CHECK (channel IN ('SCREEN', 'EMAIL', 'SMS', 'WHATSAPP')),
                CONSTRAINT notification_deliveries_status_known
                    CHECK (status IN ('PENDING', 'SENT', 'DELIVERED', 'FAILED', 'SUPPRESSED')),

                -- A suppression says why, exactly when it is one. Silence
                -- would make "did we tell them?" unanswerable, and a reason
                -- on a delivery that went out would be a lie.
                CONSTRAINT notification_deliveries_suppressed_says_why
                    CHECK ((status = 'SUPPRESSED') = (suppression_reason IS NOT NULL)),

                CONSTRAINT notification_deliveries_suppression_reason_known
                    CHECK (suppression_reason IS NULL OR suppression_reason IN (
                        'NO_CONSENT', 'OPTED_OUT', 'NO_ADDRESS', 'CHANNEL_UNAVAILABLE'
                    )),

                -- Sent exactly when dated. A delivery claiming to have gone
                -- out with no timestamp cannot be reconciled with a provider.
                CONSTRAINT notification_deliveries_sent_is_dated
                    CHECK ((status IN ('SENT', 'DELIVERED')) = (sent_at IS NOT NULL)),

                CONSTRAINT notification_deliveries_attempts_sane
                    CHECK (attempts >= 0),

                -- Exactly-once, as an index rather than a check. A retried
                -- job must not send a second SMS: that one is billed, and on
                -- WhatsApp it risks the sender's standing.
                CONSTRAINT notification_deliveries_once_per_channel
                    UNIQUE (notification_id, channel)
            )
            SQL);

        // What the queue claims: everything still waiting to go out.
        $this->addSql(<<<'SQL'
            CREATE INDEX notification_deliveries_pending_idx
                ON notification_deliveries (status, created_at)
             WHERE status = 'PENDING'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_preferences (
                user_id             uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                product_id          uuid NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                category            TEXT NOT NULL,
                channel             TEXT NOT NULL,
                enabled             BOOLEAN NOT NULL,
                updated_at          timestamptz NOT NULL DEFAULT now(),

                PRIMARY KEY (user_id, product_id, category, channel),

                CONSTRAINT notification_preferences_category_known
                    CHECK (category IN ('BILLING', 'ACCOUNT', 'SECURITY', 'SUPPORT', 'MARKETING')),
                CONSTRAINT notification_preferences_channel_known
                    CHECK (channel IN ('SCREEN', 'EMAIL', 'SMS', 'WHATSAPP')),

                -- A security notice the recipient can mute is one an attacker
                -- can mute. Non-negotiable #24, and the database is where it
                -- holds rather than in whichever service happens to check.
                CONSTRAINT notification_preferences_security_is_not_optional
                    CHECK (category <> 'SECURITY' OR enabled)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_consents (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id             uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                channel             TEXT NOT NULL,
                purpose             TEXT NOT NULL,
                granted_at          timestamptz NOT NULL DEFAULT now(),
                revoked_at          timestamptz,
                source              TEXT NOT NULL,
                evidence            jsonb NOT NULL DEFAULT '{}'::jsonb,

                CONSTRAINT notification_consents_channel_known
                    CHECK (channel IN ('SMS', 'WHATSAPP', 'EMAIL')),
                CONSTRAINT notification_consents_purpose_known
                    CHECK (purpose IN ('TRANSACTIONAL', 'MARKETING')),
                CONSTRAINT notification_consents_revoked_after_granted
                    CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
            )
            SQL);

        // One live consent per (person, channel, purpose). A second would
        // leave "are they opted in?" to whichever row a query found first,
        // and revocation has to be unambiguous.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX notification_consents_one_live
                ON notification_consents (user_id, channel, purpose)
             WHERE revoked_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('notifications.read', 'Read one''s own notifications and preferences'),
                ('notifications.manage', 'Change one''s own notification preferences and consents')
            SQL);

        // Everyone manages their own. These are not administrative
        // permissions: they govern a person's own inbox, and a tenant member
        // who could not turn off an email would have no way to stop it.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.code IN ('TENANT_ADMIN', 'USER')
              AND p.code IN ('notifications.read', 'notifications.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions
                WHERE code IN ('notifications.read', 'notifications.manage')
            )
            SQL);
        $this->addSql(
            "DELETE FROM permissions WHERE code IN ('notifications.read', 'notifications.manage')",
        );
        $this->addSql('DROP TABLE IF EXISTS notification_consents');
        $this->addSql('DROP TABLE IF EXISTS notification_preferences');
        $this->addSql('DROP TABLE IF EXISTS notification_deliveries');
        $this->addSql('DROP TABLE IF EXISTS notifications');
    }
}
