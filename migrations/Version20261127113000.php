<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The offer a commitment was agreed on, when it is no longer the one held
 * (ADR-077, 2026-11-27).
 *
 * A commitment survives a change of plan, and so now do the exit conditions it
 * was agreed with — those are values on the row and stay there. What an early
 * exit is *priced* in is the offer version's price per period, and the row
 * names only the version held today: a customer two years committed at €99
 * could move to the €9 plan the day before leaving and be billed the remaining
 * periods at €9.
 *
 * `commitment_version_id` names the version the commitment in force was agreed
 * on. **Null means "the one held"**, which is every subscription whose plan has
 * not moved under a commitment — so nothing is backfilled and no insert path
 * changes. It is written when a change of plan keeps the commitment, and cleared
 * when a later commitment takes over or a term re-arms.
 *
 * `RESTRICT`, like `offer_version_id`: a version somebody is committed on is
 * not deleted from under them.
 */
final class Version20261127113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'subscriptions.commitment_version_id: the offer version a surviving commitment was agreed on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE subscriptions ADD COLUMN commitment_version_id UUID'
            . ' REFERENCES offer_versions (id) ON DELETE RESTRICT',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP COLUMN commitment_version_id');
    }
}
