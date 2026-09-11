<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somebody can now arrive with no account and leave with a subscription.
 *
 * Until this, every account on the platform was created by somebody who
 * already had one — the installer, or a tenant administrator inviting a
 * colleague. A storefront that sells to strangers needs a third way in, and an
 * address nobody has ever confirmed is the price of it.
 *
 * **`users.email_verified_at`, nullable, and nothing waits on it.** The
 * decision was that a person buys immediately and confirms afterwards: an
 * interrupted purchase is a purchase that does not happen, and a verification
 * link that lands in spam would be the thing standing between this platform
 * and its revenue. So the column records a fact rather than gating one —
 * billing, VAT and dunning can read it and decide for themselves, which is
 * where that decision belongs.
 *
 * Every account that exists when this runs gets `now()` rather than null. They
 * were created by somebody who was already trusted with the platform — the
 * installer, or an administrator adding a colleague by address — and marking
 * them unverified would be recording a doubt that was never had.
 *
 * **`email_verifications` stores a SHA-256, never the token.** The token is
 * 256 bits of CSPRNG output and it arrives in a link, so what matters is that
 * the row cannot be replayed as a credential if the database leaks; there is
 * nothing to brute-force, so a slow hash would only make verification slower.
 * The same reasoning `refresh_tokens` already follows, and the same CHECK
 * behind it.
 */
final class Version20260911230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Self-service sign-up: an unverified address, and the token that confirms it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMPTZ');

        // Backfilled, then left to default null for everybody who arrives
        // through the storefront from here on.
        $this->addSql('UPDATE users SET email_verified_at = now() WHERE erased_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE email_verifications (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT email_verifications_hash_is_sha256
                    CHECK (token_hash ~ '^[0-9a-f]{64}$')
            )
            SQL);

        // Verification is by token, so that is the lookup. The per-user index
        // is for the other question — "did we already send one?" — which is
        // what stops a refresh of the sign-up form filling the table.
        $this->addSql('CREATE INDEX email_verifications_user_idx ON email_verifications (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS email_verifications');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS email_verified_at');
    }
}
