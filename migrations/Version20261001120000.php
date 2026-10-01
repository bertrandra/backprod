<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every subscription is a seat, and the column saying otherwise goes.
 *
 * `subscriber_kind` has been able to hold two values since 2026-09-04, and
 * since ADR-055 (2026-09-25) only one of them is reachable: the tenant surface
 * sells seats, `Sales::order()` has no argument for the other sale,
 * `openCheckoutSession` no field, and ADR-056 removed `POST /subscription`
 * entirely. What was left was a column that is constant in practice, a
 * `Subscriber` value object with a variant nothing constructs, two unique
 * indexes where one is dead, and — the part that cost something — four
 * production defaults that quietly fall back to `TENANT` for any caller that
 * forgets an optional argument.
 *
 * That default is not harmless. Since ADR-053 an organisation's subscription
 * covers nobody by itself, so a subscription created by a forgetful caller
 * would be paid for and entitle no one, buyer included. It is the shape the
 * `Reach` leak had: an argument you can omit is one that eventually is.
 *
 * **It refuses rather than deletes.** A deployment holding rows from before
 * ADR-055 has real subscriptions here, and a migration that dropped the column
 * would silently turn each into a seat belonging to nobody — or, with
 * `subscriber_user_id` made NOT NULL, fail halfway with the table already
 * altered. So it looks first and says what it found, and whoever has such rows
 * decides what they are. The operator of this platform has none: the
 * demonstration has seeded seats only since 2026-09-25, and they confirmed
 * their deployment is not yet in production.
 *
 * Irreversible, per ADR-016: the kind cannot be recovered once dropped, and a
 * `down()` restoring the column would restore it as `'USER'` for every row —
 * which is true today and would be a lie about any row that was not.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'subscriptions/orders: every subscription is a seat; subscriber_kind goes';
    }

    public function up(Schema $schema): void
    {
        $this->refuseIfAnythingIsNotASeat('subscriptions');
        $this->refuseIfAnythingIsNotASeat('orders');

        // --- subscriptions ----------------------------------------------------

        // The dead half of the pair first: one unique index per kind was the
        // right shape while there were two kinds (an application check races
        // straight through, §13.1), and with one kind the tenant one bounds a
        // set that is always empty.
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_tenant_subscription');
        $this->addSql('DROP INDEX IF EXISTS subscriptions_one_active_seat_per_person');
        $this->addSql('DROP INDEX IF EXISTS subscriptions_seat_idx');

        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                DROP CONSTRAINT IF EXISTS subscriptions_subscriber_kind_known,
                DROP CONSTRAINT IF EXISTS subscriptions_seat_names_its_holder,
                DROP COLUMN subscriber_kind,
                ALTER COLUMN subscriber_user_id SET NOT NULL
            SQL);

        // One live subscription per person per scope. "Live" keeps both
        // spellings (ADR-060): arrears are not an exit and must not free the
        // place, or a customer owing for March takes out a second in April and
        // leaves the first unpaid for ever.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_seat_per_person
                ON subscriptions (tenant_id, product_id, subscriber_user_id)
             WHERE status IN ('ACTIVE', 'PAST_DUE')
            SQL);

        // Entitlement resolution reads this on every request that asks what a
        // person may use, so the lookup stays indexed rather than scanned. No
        // longer partial: every row is a seat.
        $this->addSql(<<<'SQL'
            CREATE INDEX subscriptions_seat_idx
                ON subscriptions (subscriber_user_id, product_id)
            SQL);

        // --- orders -----------------------------------------------------------
        //
        // An order may be for a seat the subscription does not exist for yet,
        // so the column stays nullable here: a quote accepted and not yet
        // fulfilled has an order with no subscription, and `placed_by` is who
        // asked. What goes is the kind — an order that buys the organisation's
        // subscription is the sale nobody can make.
        $this->addSql('DROP INDEX IF EXISTS orders_seat_idx');

        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                DROP CONSTRAINT IF EXISTS orders_subscriber_kind_known,
                DROP CONSTRAINT IF EXISTS orders_seat_names_its_holder,
                DROP COLUMN subscriber_kind,
                ALTER COLUMN subscriber_user_id SET NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX orders_seat_idx
                ON orders (tenant_id, product_id, subscriber_user_id)
            SQL);
    }

    /**
     * Looks before altering, and says what it found.
     *
     * The count is in the message because "some rows are not seats" sends an
     * operator to write the query themselves, and the number is the first
     * thing that decides what to do about them.
     */
    private function refuseIfAnythingIsNotASeat(string $table): void
    {
        $standing = $this->connection->fetchOne(
            sprintf("SELECT count(*) FROM %s WHERE subscriber_kind <> 'USER'", $table),
        );

        $standing = is_numeric($standing) ? (int) $standing : 0;

        $this->abortIf($standing > 0, sprintf(
            '%d row(s) in `%s` are not seats. Since ADR-055 nothing can create one, so these are '
            . 'from before 2026-09-25 or from a seeder of that age. They are real subscriptions and '
            . 'this migration will not guess whose: decide what each is, set subscriber_kind to '
            . "'USER' with the person in subscriber_user_id, or delete them, and run it again.",
            $standing,
            $table,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'ADR-016: the subscriber kind cannot be recovered once dropped. Restoring the column '
            . "would write 'USER' on every row, which is true of every row today and would be a "
            . 'lie about any row that was not.',
        );
    }
}
