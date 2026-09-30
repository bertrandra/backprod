<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A project belongs to the person whose subscription paid for it (2026-09-30).
 *
 * **The hole this closes.** `projects` was keyed on `(tenant_id, product_id)`
 * and nothing else: `PostgresProjectRepository` listed and found by those two
 * columns, and the caller's own id was never passed to the query at all. So
 * anybody covered by *any* subscription on the product saw *every* project in
 * the organisation — a colleague's terrace, a colleague's client's parcel —
 * and could open, edit and delete them by id. `created_by` had been recorded
 * since the first migration and no filter had ever read it.
 *
 * That is the same shape as the entitlement hole ADR-053 closed: a number a
 * customer pays for has to bound something, and a subscription somebody buys
 * for themselves has to be *theirs*.
 *
 * **The holder is a person, not a subscription** (decided with the operator,
 * 2026-09-30). A subscription is a row with an end: cancelled and taken out
 * again is a new row, a change of offer is a new version, and a project
 * pointing at any of those would go invisible to its own owner the day the
 * paperwork moved. The person does not move. So `holder_user_id` names
 * whoever held the subscription that covered the creator, and a project is
 * reachable by that person and by whoever is on their subscription *today* —
 * delegation is a live fact, so somebody removed from a seat stops seeing its
 * work, which is what removing them means.
 *
 * **The backfill resolves what it can and says nothing it cannot.** For each
 * project it asks which live subscription on `(tenant, product)` covers
 * `created_by` — as its owner, or as one of its members — and takes that
 * owner. Where nothing resolves, `created_by` itself is the holder: the
 * creator of a project is the most honest answer available, and a project
 * whose creator was erased (`created_by` is nullable, and erasure sets it
 * null) keeps a null holder and is then reachable by administrators only.
 * That is deliberately the safe direction: an unreachable project is a
 * support question, an over-shared one is a leak.
 *
 * Retroactive precision is not available and is not claimed. Coverage at the
 * moment of creation was never recorded, so this reads the coverage that
 * exists now — correct for every project whose creator is still on the same
 * subscription, which on any deployment this young is all of them.
 */
final class Version20260930090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'projects.holder_user_id: a project belongs to the person whose subscription covered its creator.';
    }

    public function up(Schema $schema): void
    {
        // ON DELETE SET NULL, like `created_by`: erasure (§30) must not take
        // the work with the account, and a project with no holder is still a
        // project the organisation's administrator can see and reassign.
        $this->addSql(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN holder_user_id uuid REFERENCES users (id) ON DELETE SET NULL
            SQL);

        // The owner of the live subscription covering the creator — whether
        // the creator holds it or was added to it. `DISTINCT ON` with the
        // owner's own subscription first, because somebody can hold a seat and
        // also be on a colleague's, and their own is the one that is theirs.
        $this->addSql(<<<'SQL'
            UPDATE projects p
               SET holder_user_id = covering.owner_user_id
              FROM (
                    SELECT DISTINCT ON (s.tenant_id, s.product_id, person.user_id)
                           s.tenant_id,
                           s.product_id,
                           person.user_id,
                           s.owner_user_id
                      FROM subscriptions s
                      CROSS JOIN LATERAL (
                            SELECT s.owner_user_id AS user_id, 0 AS rank
                             UNION ALL
                            SELECT m.user_id, 1 FROM subscription_members m WHERE m.subscription_id = s.id
                           ) person
                     WHERE s.status = 'ACTIVE'
                       AND s.owner_user_id IS NOT NULL
                       AND person.user_id IS NOT NULL
                     ORDER BY s.tenant_id, s.product_id, person.user_id, person.rank
                   ) covering
             WHERE covering.tenant_id = p.tenant_id
               AND covering.product_id = p.product_id
               AND covering.user_id = p.created_by
            SQL);

        // Whatever is left keeps its creator. A project made before the
        // subscription that paid for it ended is still that person's work.
        $this->addSql('UPDATE projects SET holder_user_id = created_by WHERE holder_user_id IS NULL');

        // The list is `(tenant, product, holder)` ordered by `updated_at`, so
        // the index carries the holder too — the existing one would leave
        // every page filtering rows it had just fetched.
        $this->addSql(<<<'SQL'
            CREATE INDEX projects_holder_idx
                ON projects (tenant_id, product_id, holder_user_id, updated_at DESC)
             WHERE deleted_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // ADR-016: forward only. Dropping the column would not put back the
        // coverage this read, and a deployment rolled back to a schema where
        // every project is visible to every member is a leak reintroduced by
        // a migration.
        $this->throwIrreversibleMigrationException(
            'Dropping holder_user_id would make every project visible to every member again (ADR-016).',
        );
    }
}
