<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erasure that legal retention survives (non-negotiables #14 and #15).
 *
 * **A person is anonymised, never deleted, and the schema already said so.**
 * Twenty foreign keys point at `users`. Five of them RESTRICT — the audit log,
 * conversation participation, staff access, subscriptions, closed VAT periods
 * — so a DELETE cannot succeed while any of that exists. Four CASCADE, which
 * is worse: deleting the row would silently destroy notification history,
 * including the pre-renewal notices kept precisely because they have legal
 * effect. The only correct move is the one the M8 audit log already
 * established: keep the row, take away the identity.
 *
 * So `users` gains `erased_at`, and an erased row is one that can no longer
 * name anybody: no email, no display name, and an `auth_subject` replaced by
 * a tombstone that no token can ever present. The check constraint is what
 * makes that a fact rather than a promise — a half-finished erasure is
 * refused by the database, not caught in review.
 *
 * **The tombstone closes the door in both directions.** An erased subject
 * must never match a real one, and a real one must never look erased: a
 * caller who could register `erased:<uuid>` as their own subject would be
 * signing in as somebody the platform has forgotten.
 *
 * **What is kept, and why, is written down per request.** #15 says legal
 * retention wins where the law requires keeping something, and an erasure
 * that quietly kept things would be indistinguishable from one that failed.
 * `erasure_requests` records what was erased, what was retained and on what
 * ground — the accountability artefact #14 asks for, and the evidence for
 * M8's exit criterion.
 */
final class Version20260905010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anonymise a person without losing what the law requires kept';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN erased_at TIMESTAMPTZ');

        // Erased means unable to name anybody. All of it or none of it.
        $this->addSql(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_erasure_is_complete
                CHECK (
                    erased_at IS NULL
                    OR (email IS NULL AND display_name IS NULL
                        AND auth_subject LIKE 'erased:%')
                )
            SQL);

        // And the other direction: a live account may not wear a tombstone,
        // or somebody could sign in as a person who has been forgotten.
        $this->addSql(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_tombstone_belongs_to_the_erased
                CHECK (auth_subject NOT LIKE 'erased:%' OR erased_at IS NOT NULL)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE erasure_requests (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                -- The person erased. RESTRICT, like the audit log: the record
                -- of an erasure must outlive nothing, least of all itself.
                subject_user_id UUID NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                -- Who carried it out. An erasure is a privileged act on
                -- somebody else's data and is never anonymous.
                requested_by  UUID NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                requested_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                -- What was cleared, and what was kept with the ground for
                -- keeping it. JSONB because the categories will change as the
                -- platform grows and a column per category would not.
                erased        JSONB NOT NULL DEFAULT '{}'::jsonb,
                retained      JSONB NOT NULL DEFAULT '{}'::jsonb,

                -- Nobody is erased twice. A second request would record a
                -- second, emptier outcome and cast doubt on the first.
                CONSTRAINT erasure_requests_once_per_person UNIQUE (subject_user_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('admin.privacy.erase', 'Carry out an RGPD erasure request')
            SQL);

        // PLATFORM_ADMIN alone. Support answers a customer's questions; this
        // destroys their identity, and the two are not the same authority.
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE p.code = 'admin.privacy.erase'
               AND r.code = 'PLATFORM_ADMIN'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                   SELECT id FROM platform_permissions WHERE code = 'admin.privacy.erase'
             )
            SQL);
        $this->addSql("DELETE FROM platform_permissions WHERE code = 'admin.privacy.erase'");
        $this->addSql('DROP TABLE IF EXISTS erasure_requests');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tombstone_belongs_to_the_erased');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_erasure_is_complete');
        $this->addSql('ALTER TABLE users DROP COLUMN erased_at');
    }
}
