<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\Reach;
use Doctrine\DBAL\ArrayParameterType;

/**
 * The reach clause, written once (2026-09-30).
 *
 * The same rule as {@see \App\Billing\Infrastructure\DocumentPersonSql} and
 * {@see \App\Billing\Infrastructure\DocumentWindowSql}: a page and its total
 * must not be able to disagree, and the only way to guarantee that is for
 * both to compose the same string.
 *
 * Three clauses and not two, because "everything" and "nothing" are both
 * real answers here: an administrator reaches every project the organisation
 * holds, and a member covered by no live subscription reaches none. An empty
 * `IN ()` is a syntax error in PostgreSQL and `IN (NULL)` is silently false,
 * so neither is left to the parameter binder to express.
 */
final class ReachSql
{
    /**
     * A boolean expression over the `holder_user_id` of `$table`.
     *
     * @param string $table alias of a `projects` row
     */
    public static function clause(Reach $reach, string $table): string
    {
        if ($reach->isEverything()) {
            return 'TRUE';
        }

        if ($reach->isNothing()) {
            return 'FALSE';
        }

        // No `OR holder_user_id IS NULL`: a project whose holder was erased
        // (§30 sets the column null) belongs to nobody who can be named, and
        // is reachable by an administrator only. The safe direction.
        return sprintf('%s.holder_user_id IN (:reachHolders)', $table);
    }

    /**
     * The parameters the clause needs, empty when it needs none.
     *
     * @return array<string, list<string>>
     */
    public static function parameters(Reach $reach): array
    {
        return $reach->isEverything() || $reach->isNothing()
            ? []
            : ['reachHolders' => $reach->holders()];
    }

    /**
     * The parameter types, empty when there are no parameters.
     *
     * @return array<string, ArrayParameterType>
     */
    public static function types(Reach $reach): array
    {
        return $reach->isEverything() || $reach->isNothing()
            ? []
            : ['reachHolders' => ArrayParameterType::STRING];
    }
}
