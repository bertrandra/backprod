<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The freemium is taken once, and once for all (2026-09-27, spec §6.4).
 *
 * A freemium is a plan at 0 that runs for one period and does not renew.
 * Without a rule about *how often* somebody may take one, five free days are
 * retaken every five days and the product is free for ever by recurrence — so
 * an account has the right to one freemium per product, and to exactly one.
 *
 * **The fact is snapshotted onto the subscription**, exactly as the terms are
 * (§13.1) and for the same reason: a join to the offer version would say what
 * that plan is *today*, and what has to be remembered is what was sold. An
 * offer repriced to €1 tomorrow must not turn last month's free trial into a
 * purchase, nor release the right to take another.
 *
 * ```sql
 * CREATE UNIQUE INDEX subscriptions_one_freemium_ever
 *     ON subscriptions (product_id, coalesce(subscriber_user_id, tenant_id))
 *  WHERE is_freemium;
 * ```
 *
 * **No status filter, and that is the whole rule.** This is what distinguishes
 * it from `subscriptions_one_active_seat_per_person`, which looks only at the
 * living: a freemium that expired six months ago still forbids a new one. Add
 * `WHERE status = 'ACTIVE'` here and the rule becomes "one at a time", which
 * is not a limit at all.
 *
 * **An index and not an application check** — the same reason §13.1 gives for
 * the active-subscription indexes: two simultaneous requests each read "none
 * yet" and both insert. A `SELECT` then `INSERT` is not a rule, it is a race
 * that usually loses.
 *
 * `coalesce(subscriber_user_id, tenant_id)` carries both kinds of subscriber.
 * The tenant surface sells seats only (ADR-055), so in practice the freemium
 * is a person's; `TENANT` remains a column and carries the rows a deployment
 * already has, and one index covers both rather than two covering one each.
 *
 * **Per product**, because a subscription always names one: tasting Plan has
 * never said anything about Boreas.
 *
 * **Deliberately no CHECK tying `is_freemium` to `renewal = 'ENDS_AT_TERM'`,**
 * tempting as it looks. The freemium is the lowest plan, so leaving it is an
 * upgrade (§6.2) — and an upgrade re-snapshots the terms from the arriving
 * version, which renews. A subscription that began as a freemium and has since
 * moved up keeps `is_freemium` true, because the right has been used; a CHECK
 * would refuse that move and lock somebody inside the free plan for ever. What
 * a freemium may be started *from* is decided at the door, on the offer's own
 * properties: a price of zero and a renewal that stops at the term.
 */
final class Version20260927110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A freemium is taken once per product and per account, for ever';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE subscriptions
                ADD COLUMN is_freemium boolean NOT NULL DEFAULT false
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_freemium_ever
                ON subscriptions (product_id, coalesce(subscriber_user_id, tenant_id))
             WHERE is_freemium
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN subscriptions.is_freemium IS
                'Whether this subscription was taken out on a freemium offer (2026-09-27, spec §6.4). Snapshotted at subscription from the offer version, like the terms, and never recomputed: it records what was sold. It survives a move to another plan, because the right to a freemium has then been used. subscriptions_one_freemium_ever makes it once per product and per account, whatever the status.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping the column would forget which accounts have already had
        // their free period — silently, and in the direction that gives every
        // one of them another. Nothing reconstructs it from the rows that
        // remain: the offer version says what that plan is today, which is
        // precisely why the fact was copied here in the first place.
        // ADR-016: a rollback in production means restoring a backup.
        $this->throwIrreversibleMigrationException(
            'subscriptions records which accounts have used their freemium; dropping it hands everybody a second one (restore a backup instead, ADR-016).',
        );
    }
}
