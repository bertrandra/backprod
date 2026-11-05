<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan accepts project schema 15 (2026-11-05).
 *
 * Plan's schema 15 is a roof drawn by hand in its roof mode: a jerkinhead
 * ("cul de geai") whose ridge overhangs the gable wall and carries the small
 * hip forward, the point of a cross gable clipped the same way, and an eave
 * kicked out over a sprocket ("queue de vache") with its own overhang. A
 * building without them still writes the schema it wrote before, so only
 * those documents need 15 — and a Plan release that writes it must not be
 * deployed before this migration has run, or its first save of such a
 * building is refused with `422 UNSUPPORTED_SCHEMA_VERSION`. The same shape
 * as `Version20261104090000`:
 *
 * - **Added to, never replaced.** Every version already accepted stays, and
 *   the list comes back sorted and without duplicates.
 * - **Nothing where there is nothing.** A deployment with no `plan` product,
 *   or whose Plan declares no list, is left as it was.
 * - **Forward only.** Taking a version back would refuse documents already
 *   saved in it.
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan accepts project schema 15';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE product_configuration pc
               SET value = jsonb_build_object('supported', (
                       SELECT jsonb_agg(v ORDER BY v)
                         FROM (
                             SELECT (e)::int AS v
                               FROM jsonb_array_elements_text(pc.value -> 'supported') AS e
                             UNION
                             SELECT 15
                         ) AS versions
                   )),
                   updated_at = now()
              FROM products p
             WHERE p.id = pc.product_id
               AND p.code = 'plan'
               AND pc.key = 'project_schema_versions'
               AND jsonb_typeof(pc.value -> 'supported') = 'array'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Removing a schema version would refuse documents already saved in it.',
        );
    }
}
