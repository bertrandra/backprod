<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan accepts project schemas 5 and 6 (2026-10-27).
 *
 * Schema 5 reached the demonstration's declaration on 2026-10-25 and never
 * the live row: production still answered `supported: [1, 2, 3, 4]`, so the
 * first save from Plan's schema-5 release would have been refused. Schema 6
 * is the next one Plan writes. Both are added here rather than typed into the
 * console, because a deployment now moves forward by approving its
 * migrations on the setup page, and a version a product writes belongs with
 * the release that lets it.
 *
 * **Added to, never replaced.** The versions already accepted stay: a
 * document an earlier release wrote is still a document its owner opens. The
 * list comes back sorted and without duplicates, whatever order it was in.
 *
 * **Nothing where there is nothing.** A deployment with no `plan` product, or
 * one whose Plan never declared a list, is left as it was — that product
 * accepts no document at all, which is an operator's decision this migration
 * does not get to make for them.
 *
 * **Forward only.** Taking a version back would refuse documents already
 * saved in it.
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan accepts project schemas 5 and 6';
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
                             SELECT unnest(ARRAY[5, 6])
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
