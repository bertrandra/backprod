<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A grant can say whether it opens the product to the tenant's people
 * (2026-09-25).
 *
 * ADR-053 made coverage a question about subscriptions, and excluded grants
 * on a stated principle: *staff hand out a feature, never a seat*. The
 * principle is right — a support engineer restoring one missing capability
 * must not thereby hand every member of the organisation the workspace — and
 * the consequence was not noticed: **the platform could no longer give a
 * trial at all.** A granted product lit up its features, said "provided by
 * the platform" on the subscription screen, and refused every workspace.
 *
 * The answer is not to make a grant cover people. It is to let the platform
 * *say* which of the two it is doing, because they really are two things:
 *
 * ```text
 * covers_people = false   this tenant has this feature      (an exception)
 * covers_people = true    these people may use this product (a trial)
 * ```
 *
 * **Default false**, which is what every existing grant meant when it was
 * written and what a support exception means when nobody thinks about it.
 * Fail closed: the wider answer is the one somebody has to choose.
 *
 * The flag sits on every row of a grant rather than on a grant header,
 * because a grant has no header — it is the set of `entitlements` rows with
 * `source = 'GRANT'` for a (tenant, product), rewritten whole each time it is
 * given. A table for it would be a second place for the same fact to be
 * wrong in.
 */
final class Version20260925140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A platform grant says whether it covers the tenant\'s people';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE entitlements
                ADD COLUMN covers_people boolean NOT NULL DEFAULT false
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN entitlements.covers_people IS
                'Whether this grant lets the tenant''s members reach the product, or only adds a feature (2026-09-25). Meaningful for source = GRANT; a subscription''s rows carry coverage on the subscription itself, where the people are named.'
            SQL);

        // A subscription's rows never answer this question: who a
        // subscription covers is its owner and the people on it (ADR-053),
        // named in `subscription_members`. A true here would be a second
        // answer to a question that already has one.
        $this->addSql(<<<'SQL'
            ALTER TABLE entitlements
                ADD CONSTRAINT entitlements_only_a_grant_covers_people
                CHECK (source = 'GRANT' OR covers_people = false)
            SQL);

        // What `covers()` reads on every request to a workspace.
        $this->addSql(<<<'SQL'
            CREATE INDEX entitlements_covering_grants_idx
                ON entitlements (tenant_id, product_id, valid_until)
             WHERE source = 'GRANT' AND covers_people
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible: nothing is renamed and no row is destroyed. Going back
        // takes away the platform's ability to open a trial, which is the
        // state this migration found.
        $this->addSql('DROP INDEX IF EXISTS entitlements_covering_grants_idx');
        $this->addSql('ALTER TABLE entitlements DROP CONSTRAINT IF EXISTS entitlements_only_a_grant_covers_people');
        $this->addSql('ALTER TABLE entitlements DROP COLUMN IF EXISTS covers_people');
    }
}
