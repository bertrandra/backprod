<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * One list of features, kept by the platform (2026-09-24, step 3 of
 * `docs/translatable-fields-spec.md`).
 *
 * A feature is not a thing a product owns; it is a word the platform and a
 * product's code have agreed on. `max_projects` existed once per product —
 * five rows on the demonstration world, all meaning the same thing — and
 * the code that reads it (`ProjectWorkspace::QUOTA`,
 * `SubscriptionPeople::USERS_FEATURE`) depended on every one of those rows
 * having been seeded with the same spelling and the same kind. It is now
 * one row, and `features.code` is unique full stop.
 *
 * **This is the migration that can go wrong quietly**, so it refuses
 * rather than guesses:
 *
 *   - two rows sharing a code but disagreeing about `kind` or `unit` stop
 *     the migration. Merging a QUOTA into a BOOLEAN would reinterpret every
 *     grant written against one of them, which is a price somebody is
 *     paying (ADR-033's rule, applied to the thing being priced);
 *   - the survivor is the oldest row for each code, by `created_at` then
 *     `id`, so a rerun on a restored dump picks the same one;
 *   - every grant and every entitlement is repointed inside the same
 *     transaction as the delete, so there is no moment where a subscription
 *     grants a feature that no longer exists.
 *
 * What cannot collide, and why: `offer_version_features` is keyed by
 * (version, feature), and a version belongs to one product whose codes were
 * already unique, so no version can end up granting the same survivor
 * twice. `entitlements` is unique per (tenant, product, feature) for grants,
 * and the product is part of that key, so two products' entitlements stay
 * two rows. Translations *can* collide — the same locale on two rows being
 * merged — and the survivor's wins.
 *
 * Irreversible: `down()` would have to invent which product each feature
 * belonged to, and the rows it was merged from no longer exist to say.
 */
final class Version20260924110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Features become one platform-wide list, merging the duplicates by code';
    }

    public function up(Schema $schema): void
    {
        // A disagreement is not something to resolve at 3 a.m. by a rule
        // nobody chose. `RAISE` aborts the whole migration.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE conflict text;
            BEGIN
                SELECT string_agg(code, ', ')
                  INTO conflict
                  FROM (
                      SELECT code
                        FROM features
                       GROUP BY code
                      HAVING count(DISTINCT kind) > 1
                          OR count(DISTINCT coalesce(unit, '')) > 1
                  ) AS disagreeing;

                IF conflict IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Cannot merge features: % are spelled the same on different products but disagree about kind or unit. Reconcile them before running this migration.',
                        conflict;
                END IF;
            END $$
            SQL);

        // The survivor of each code: the oldest, deterministically.
        $this->addSql(<<<'SQL'
            CREATE TEMP TABLE feature_merge ON COMMIT DROP AS
            SELECT f.id AS old_id, s.id AS new_id
              FROM features f
              JOIN (
                  SELECT DISTINCT ON (code) code, id
                    FROM features
                   ORDER BY code, created_at, id
              ) s ON s.code = f.code
             WHERE f.id <> s.id
            SQL);

        // Translations first: the survivor keeps its own, and a duplicate's
        // is dropped rather than overwriting a value somebody chose.
        $this->addSql(<<<'SQL'
            DELETE FROM feature_translations t
             USING feature_merge m
             WHERE t.feature_id = m.old_id
               AND EXISTS (
                   SELECT 1 FROM feature_translations k
                    WHERE k.feature_id = m.new_id AND k.locale = t.locale
               )
            SQL);
        $this->addSql('UPDATE feature_translations t SET feature_id = m.new_id FROM feature_merge m WHERE t.feature_id = m.old_id');

        $this->addSql('UPDATE offer_version_features g SET feature_id = m.new_id FROM feature_merge m WHERE g.feature_id = m.old_id');
        $this->addSql('UPDATE entitlements e SET feature_id = m.new_id FROM feature_merge m WHERE e.feature_id = m.old_id');

        $this->addSql('DELETE FROM features f USING feature_merge m WHERE f.id = m.old_id');

        // The code is now the identity of a feature on this platform.
        $this->addSql('ALTER TABLE features DROP CONSTRAINT IF EXISTS features_code_unique');
        $this->addSql('ALTER TABLE features DROP COLUMN product_id');
        $this->addSql('ALTER TABLE features ADD CONSTRAINT features_code_unique UNIQUE (code)');

        // Retired rather than deleted, like a product: a feature an offer
        // version grants may never be removed, and there has to be a way to
        // stop offering a new one.
        $this->addSql('ALTER TABLE features ADD COLUMN active boolean NOT NULL DEFAULT true');

        $this->addSql("COMMENT ON TABLE features IS 'What may be sold, platform-wide (2026-09-24): a word the platform and a product s code have agreed on. A grant still belongs to a product, because it lives on an offer version.'");

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description)
            VALUES ('staff.features.manage', 'Keep the platform''s list of features')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.features.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Which product each merged feature belonged to is exactly what this
        // migration threw away, and the rows that knew are gone (ADR-016).
        throw new IrreversibleMigration(
            'Features were merged into one platform-wide list; the product each duplicate belonged to no longer exists to restore.',
        );
    }
}
