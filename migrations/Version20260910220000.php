<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts this platform owns, so it needs no identity provider (U12).
 *
 * ADR-014 delegated identity to Supabase and had the backend only ever *verify*
 * a JWT. That was the right call for a platform with no login screen, and it
 * became the wrong one for a distribution: the deployment target is a host
 * offering PHP, PostgreSQL and JavaScript, and "sign in" was the single thing
 * that reached outside all three. So PHP issues the token now, and the only
 * external service left is the database.
 *
 * **Credentials live in their own table, not in columns on `users`.**
 *
 * `users` is provider-agnostic by design — `auth_subject` is whatever the
 * provider called this person, and the tenant, product and role decisions hang
 * off the row rather than off the credential. Putting a password there would tie
 * the identity to one way of proving it. Here, a deployment that later moves back
 * to an external provider deletes rows from one table and changes an adapter.
 *
 * It also matters for erasure: RGPD deletion can drop the credential without
 * touching the web of constraints on `users` that §15's retention rules depend
 * on.
 *
 * **`password_hash LIKE '$%'` is the constraint worth reading.**
 *
 * Every hash `password_hash()` produces begins with `$` — `$2y$` for bcrypt,
 * `$argon2id$` for Argon2. A plaintext password almost never does. So the
 * database itself refuses to store one, and the day somebody writes
 * `'password_hash' => $password` by mistake, the INSERT fails instead of
 * succeeding quietly and leaving a plaintext password in a table nobody reads by
 * eye. This is the same reasoning as "exactly-once is a unique index, never a
 * check": the guarantee belongs where nothing can route around it.
 *
 * **Refresh tokens are stored hashed, and rotation is detectable.**
 *
 * The table holds a SHA-256 of the token and a CHECK that the value *is* 64 hex
 * characters, so the token itself cannot enter the table by any route — a stolen
 * database gives an attacker nothing to present. Each use revokes the token and
 * records which one replaced it, so a chain is reconstructable; presenting an
 * already-revoked token is therefore a detectable event rather than an
 * indistinguishable one, and the service treats it as theft and revokes the whole
 * family.
 */
final class Version20260910220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local accounts: credentials and rotatable refresh tokens (U12, supersedes ADR-014 for issuance)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE local_credentials (
                user_id UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT local_credentials_email_not_blank CHECK (btrim(email) <> ''),
                CONSTRAINT local_credentials_email_has_an_at CHECK (position('@' IN email) > 1),
                CONSTRAINT local_credentials_password_is_hashed CHECK (password_hash LIKE '$%')
            )
            SQL);

        // Case-insensitively unique. Somebody who signed up as `Ada@acme.test`
        // and types `ada@acme.test` is the same person, and two rows differing
        // only in case would be two accounts nobody could tell apart.
        $this->addSql(
            'CREATE UNIQUE INDEX local_credentials_email_unique ON local_credentials (lower(email))',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_refresh_tokens (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL,
                issued_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at TIMESTAMPTZ NOT NULL,
                revoked_at TIMESTAMPTZ,
                replaced_by UUID REFERENCES auth_refresh_tokens(id) ON DELETE SET NULL,
                CONSTRAINT auth_refresh_tokens_hash_unique UNIQUE (token_hash),
                CONSTRAINT auth_refresh_tokens_hash_is_a_sha256 CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT auth_refresh_tokens_expires_after_issue CHECK (expires_at > issued_at),
                CONSTRAINT auth_refresh_tokens_replacement_is_revoked
                    CHECK (replaced_by IS NULL OR revoked_at IS NOT NULL)
            )
            SQL);

        // Every lookup is "this hash, is it still live?", and every revocation
        // sweep is "this user's tokens". Two indexes, both on the read the code
        // actually issues.
        $this->addSql(<<<'SQL'
            CREATE INDEX auth_refresh_tokens_live_idx
                ON auth_refresh_tokens (user_id)
                WHERE revoked_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_refresh_tokens');
        $this->addSql('DROP TABLE local_credentials');
    }
}
