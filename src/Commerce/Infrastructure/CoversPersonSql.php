<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

/**
 * Whether a subscription is **this person's** — one rule, written once.
 *
 * Buying covers people and membership does not (ADR-053): a subscription
 * covers whoever took it out, plus those they have added within the number of
 * people their offer sells. Joining an organisation gets somebody a role, never
 * an entitlement.
 *
 * That rule decides three different things, and until now it was written
 * separately for each: the entitlement query that opens or shuts a workshop,
 * {@see \App\Commerce\Service\Subscriptions::coversPerson()} in PHP, and —
 * the one that was missing — what to tell somebody asking what they are on.
 * Two implementations of one rule eventually disagree, and the disagreement
 * here reads as "you have no subscription" to somebody who is working inside
 * one. Which is exactly what it read as, to every colleague on somebody else's
 * seat, until 2026-10-01.
 *
 * Three ways in, deliberately all three:
 *
 *   - `owner_user_id` — who activated it, and whose it is to manage;
 *   - `subscriber_user_id` — who it was addressed to;
 *   - `subscription_members` — who the owner added.
 *
 * **Two of the three are the same person for every subscription this platform
 * creates**, and that is worth saying plainly rather than dressing up:
 * `applyActivate()` writes `owner_user_id = subscriber_user_id` for every
 * seat, so removing either from the exclusion changes no answer any test can
 * construct — tried, on the whole of `SubscriptionCoverageTest`, and it passes
 * either way. Both are kept because this is a faithful move of the rule as it
 * stood, and quietly narrowing the query that decides whether a workshop opens
 * is not a thing to do while refactoring. Neither is defended by a test, and a
 * reader should know that.
 */
final class CoversPersonSql
{
    /**
     * @param string $subscription alias of a `subscriptions` row
     * @param string $userId       a bound parameter naming the person, which
     *                             must be cast because it is compared against
     *                             uuid columns and PDO sends it as text
     */
    public static function clause(string $subscription, string $userId): string
    {
        return sprintf(
            '(%1$s.owner_user_id = CAST(%2$s AS uuid)'
            . ' OR %1$s.subscriber_user_id = CAST(%2$s AS uuid)'
            . ' OR EXISTS (SELECT 1 FROM subscription_members cpm'
            . ' WHERE cpm.subscription_id = %1$s.id AND cpm.user_id = CAST(%2$s AS uuid)))',
            $subscription,
            $userId,
        );
    }

    /**
     * The same question negated, for a caller excluding what is not theirs.
     *
     * Written here rather than by wrapping {@see self::clause()} in `NOT`,
     * because the columns are nullable: `NOT (NULL = x)` is NULL, which a
     * `WHERE` treats as false and a `NOT EXISTS` subquery therefore keeps.
     * `IS DISTINCT FROM` is the comparison that answers for a null, and
     * getting that wrong in the entitlement query is a workshop that opens
     * for somebody it should refuse.
     *
     * @param string $subscription alias of a `subscriptions` row
     * @param string $userId       a bound parameter naming the person
     */
    public static function excludes(string $subscription, string $userId): string
    {
        return sprintf(
            '%1$s.owner_user_id IS DISTINCT FROM CAST(%2$s AS uuid)'
            . ' AND %1$s.subscriber_user_id IS DISTINCT FROM CAST(%2$s AS uuid)'
            . ' AND NOT EXISTS (SELECT 1 FROM subscription_members cpm'
            . ' WHERE cpm.subscription_id = %1$s.id AND cpm.user_id = CAST(%2$s AS uuid))',
            $subscription,
            $userId,
        );
    }
}
