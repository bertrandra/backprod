<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An order names its subscriber (2026-09-18): the organisation, or a person.
 *
 * Subscriptions have had a subscriber since §13.1 — `TENANT` entitles every
 * member, `USER` is a seat that entitles one person — but an order, the
 * sale that starts one, could only be the organisation's. Self-service
 * showed why that is not enough: somebody who signs up at an organisation's
 * root and chooses an offer pays with their own card for their own seat,
 * and at an organisation that already subscribes the only thing an
 * organisation-only order could do was be refused.
 *
 * So the order carries the subscriber the subscription will have, decided
 * at checkout and never changed. Every order that exists is the
 * organisation's, which the default says.
 */
final class Version20260918130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'orders.subscriber_kind / subscriber_user_id: an order may buy a seat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN subscriber_kind TEXT NOT NULL DEFAULT 'TENANT',
                ADD COLUMN subscriber_user_id UUID REFERENCES users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT orders_subscriber_kind_known CHECK (subscriber_kind IN ('TENANT', 'USER')),
                ADD CONSTRAINT orders_seat_names_its_holder
                    CHECK ((subscriber_kind = 'USER') = (subscriber_user_id IS NOT NULL))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX orders_seat_idx
                ON orders (tenant_id, product_id, subscriber_user_id)
                WHERE subscriber_kind = 'USER'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX orders_seat_idx');
        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                DROP CONSTRAINT orders_seat_names_its_holder,
                DROP CONSTRAINT orders_subscriber_kind_known,
                DROP COLUMN subscriber_user_id,
                DROP COLUMN subscriber_kind
            SQL);
    }
}
