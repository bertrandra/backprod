<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

/**
 * How many of a subscription's places are taken, written once (2026-09-30).
 *
 * Two readers need the same number and must not be able to disagree about it:
 * the quota check that refuses a tenth person on a subscription sold for nine,
 * and the `places_used` the organisation screen shows beside `places_sold`. A
 * second copy would be a number a customer paid for, computed twice — the
 * reason {@see \App\Commerce\Domain\Places} already exists for the other half
 * of the same question.
 *
 * **An administrator takes no place** (decided with the operator, 2026-09-30).
 * They administer the organisation — its subscriptions, its members, its
 * billing — and being added to a colleague's subscription so they can help
 * with the work is not what the offer sold seats for. So anybody holding
 * `TENANT_ADMIN` on the subscription's own `(tenant, product)` is excluded,
 * the holder included: the rule is about the office, not about how somebody
 * came to be on the row.
 *
 * The consequence is worth naming rather than discovering: an organisation
 * that appoints five administrators gives itself five free places. That is
 * the operator's decision and not an oversight.
 *
 * The role is read from `tenant_member_roles`, which is where a role lives —
 * admin is a property of a membership, never of a user (§12.2).
 */
final class PlacesUsedSql
{
    /** The role that administers a customer's organisation. */
    public const ADMINISTRATOR = 'TENANT_ADMIN';

    /**
     * Whether `$userId` administers `$subscription`'s tenant and product.
     *
     * Expressions over aliases, so a caller can use it in a WHERE or a
     * SELECT without repeating the join.
     *
     * @param string $userId       an expression yielding a user id
     * @param string $subscription alias of a `subscriptions` row
     */
    public static function administers(string $userId, string $subscription): string
    {
        return sprintf(
            'EXISTS ('
            . 'SELECT 1 FROM tenant_member_roles tmr'
            . ' JOIN roles r ON r.id = tmr.role_id'
            . ' WHERE tmr.user_id = %1$s'
            . ' AND tmr.tenant_id = %2$s.tenant_id'
            . ' AND tmr.product_id = %2$s.product_id'
            . " AND r.code = '%3\$s')",
            $userId,
            $subscription,
            self::ADMINISTRATOR,
        );
    }

    /**
     * The places `$subscription` has taken: its holder and its members, minus
     * whoever administers the organisation.
     *
     * A scalar subquery, so it composes into the read model's SELECT exactly
     * as the member count it replaces did.
     *
     * @param string $subscription alias of a `subscriptions` row
     */
    public static function count(string $subscription): string
    {
        return sprintf(
            '('
            // The holder counts as one, unless there is none — an
            // organisation's own subscription names nobody (ADR-055 left the
            // column and took the button away), and there is then nobody to
            // count but the members.
            . 'CASE WHEN %1$s.owner_user_id IS NOT NULL AND NOT %2$s THEN 1 ELSE 0 END'
            . ' + (SELECT count(*) FROM subscription_members m'
            . ' WHERE m.subscription_id = %1$s.id AND NOT %3$s)'
            . ')',
            $subscription,
            self::administers($subscription . '.owner_user_id', $subscription),
            self::administers('m.user_id', $subscription),
        );
    }
}
