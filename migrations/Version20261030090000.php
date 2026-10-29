<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan accepts project schemas 7 to 12 (2026-10-29).
 *
 * Declared ahead of the releases that will write them, at the operator's
 * request, so a Plan release moving to the next schema is not refused on its
 * first save while the platform waits for its own deployment. The same shape
 * as `Version20261027090000`:
 *
 * - **Added to, never replaced.** Every version already accepted stays, and
 *   the list comes back sorted and without duplicates.
 * - **Nothing where there is nothing.** A deployment with no `plan` product,
 *   or whose Plan declares no list, is left as it was.
 * - **Forward only.** Taking a version back would refuse documents already
 *   saved in it.
 */
final class Version20261030090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan accepts project schemas 7 to 12';
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
                             SELECT generate_series(7, 12)
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
