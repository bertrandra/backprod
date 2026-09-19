<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A password can be reset, and a subscription has an owner and its people
 * (2026-09-19).
 *
 * **`password_resets`** is `email_verifications` for the other link an
 * inbox may hold: a token, sent once, stored as its SHA-256, single-use and
 * expiring. Two purposes on one table because they are one mechanism —
 * *reset*, asked for by somebody who forgot, and *invitation*, for a person
 * an owner added by address who has never had a password at all.
 *
 * **`subscriptions.owner_user_id`** is who activated it: the person a seat
 * is for, or the administrator who bought the organisation's. The owner
 * manages the subscription's people, within the quota the offer sold.
 *
 * **`subscription_members`** are the people a subscription covers beside
 * its owner. Entitlement resolution counts them (§13.1's "who is asking"),
 * so a member of Ada's seat is entitled by it — and a member of nobody's is
 * not. Cascades with the subscription and with the person: a seat that
 * ends covers nobody, and an erased person is on no list.
 */
final class Version20260919090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password resets and invitations; a subscription has an owner and its people';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE password_resets (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                purpose TEXT NOT NULL DEFAULT 'RESET' CHECK (purpose IN ('RESET', 'INVITATION')),
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT password_resets_hash_is_sha256
                    CHECK (token_hash ~ '^[0-9a-f]{64}$')
            )
            SQL);
        $this->addSql('CREATE INDEX password_resets_user_idx ON password_resets (user_id)');

        $this->addSql('ALTER TABLE subscriptions ADD COLUMN owner_user_id UUID REFERENCES users (id) ON DELETE SET NULL');
        // A seat's owner is its holder; the organisation's subscriptions
        // from before today have no recorded buyer and stay ownerless until
        // an administrator is made one.
        $this->addSql("UPDATE subscriptions SET owner_user_id = subscriber_user_id WHERE subscriber_kind = 'USER'");

        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_members (
                subscription_id UUID NOT NULL REFERENCES subscriptions (id) ON DELETE CASCADE,
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                added_by UUID REFERENCES users (id) ON DELETE SET NULL,
                added_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (subscription_id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX subscription_members_user_idx ON subscription_members (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS subscription_members');
        $this->addSql('ALTER TABLE subscriptions DROP COLUMN IF EXISTS owner_user_id');
        $this->addSql('DROP TABLE IF EXISTS password_resets');
    }
}
