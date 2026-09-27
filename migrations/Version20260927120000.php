<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A refresh token belongs to one sign-in, and a sign-in has an age
 * (2026-09-27, ADR-062).
 *
 * **`family_id`** names the sign-in a token descends from: the id of the
 * token that sign-in issued, carried by every rotation after it. Reuse of a
 * spent token used to revoke every token the *account* held, so a glitch on
 * one device signed the person out of all of them; it now revokes the family
 * it came from. Password reset still ends everything — that one is about the
 * account.
 *
 * **`family_started_at`** is when that sign-in happened. Rotation used to
 * give a chain thirty more days on every refresh, so a session kept busy —
 * or a stolen one kept busy — never had to end. It now ends at a fixed age
 * whatever its activity (`AUTH_SESSION_MAX_AGE`).
 *
 * Backfilled by walking each chain from its root, so a session live today
 * keeps its sign-in as one family rather than becoming one family per
 * rotation — which would make revoking "this sign-in" revoke one link of it.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh tokens belong to a sign-in (family) that has a start date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE auth_refresh_tokens
                ADD COLUMN family_id UUID,
                ADD COLUMN family_started_at TIMESTAMPTZ
            SQL);

        // A root is a token no other token was replaced by.
        $this->addSql(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT t.id, t.id AS root, t.issued_at AS started
                  FROM auth_refresh_tokens t
                 WHERE NOT EXISTS (SELECT 1 FROM auth_refresh_tokens p WHERE p.replaced_by = t.id)
                UNION ALL
                SELECT t.id, c.root, c.started
                  FROM chain c
                  JOIN auth_refresh_tokens p ON p.id = c.id
                  JOIN auth_refresh_tokens t ON t.id = p.replaced_by
            )
            UPDATE auth_refresh_tokens a
               SET family_id = c.root, family_started_at = c.started
              FROM chain c
             WHERE c.id = a.id
            SQL);

        // Anything the walk could not reach is its own sign-in.
        $this->addSql(<<<'SQL'
            UPDATE auth_refresh_tokens
               SET family_id = coalesce(family_id, id),
                   family_started_at = coalesce(family_started_at, issued_at)
             WHERE family_id IS NULL OR family_started_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE auth_refresh_tokens
                ALTER COLUMN family_id SET NOT NULL,
                ALTER COLUMN family_started_at SET NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX auth_refresh_tokens_family_live_idx
                ON auth_refresh_tokens (family_id)
             WHERE revoked_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS auth_refresh_tokens_family_live_idx');
        $this->addSql('ALTER TABLE auth_refresh_tokens DROP COLUMN family_started_at, DROP COLUMN family_id');
    }
}
