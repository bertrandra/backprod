<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A refund made for a named purpose is made once (ADR-076, 2026-11-27).
 *
 * A change of plan gives the unconsumed part of the period back **before** it
 * moves the plan, in a transaction of its own. A crash between the two left
 * the customer refunded and still on the old plan, and the retry — which the
 * comment above it promised would "find nothing left to credit" — found
 * `amount - refunded` still returnable and refunded the same share again.
 *
 * `purpose_key` names what the money was returned *for*: a proration names
 * the subscription and the period it cut. One live refund per name, by index
 * and never by a check — two retries arriving together would both pass a
 * check. A refund the provider FAILED returned nothing, so it frees the name.
 *
 * Null for every refund nobody named: an operator's refund from the console
 * may be repeated as often as there is money left to return.
 */
final class Version20261127101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A refund made for a named purpose is made once: refunds.purpose_key and its unique index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refunds ADD COLUMN purpose_key TEXT');
        $this->addSql(
            'CREATE UNIQUE INDEX refunds_once_per_purpose ON refunds (purpose_key)'
            . " WHERE purpose_key IS NOT NULL AND status <> 'FAILED'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX refunds_once_per_purpose');
        $this->addSql('ALTER TABLE refunds DROP COLUMN purpose_key');
    }
}
