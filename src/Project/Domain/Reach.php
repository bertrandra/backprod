<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * Which projects a caller may reach (2026-09-30).
 *
 * A project belongs to the person whose subscription paid for it, and is
 * reachable by that person and by whoever is on their subscription today.
 * Until this existed `projects` was queried on `(tenant_id, product_id)`
 * alone and the caller's own id never reached the query, so every member
 * covered by any subscription saw and could edit every project in the
 * organisation.
 *
 * **A required argument, not a filter somebody remembers to apply.** It is
 * passed to every read in {@see ProjectRepository}, so a query that forgets
 * it does not compile. A `WHERE` clause added by convention is the shape the
 * leak had in the first place — the column to filter on had existed all
 * along.
 *
 * **Empty is not everything.** A caller who holds nothing reaches nothing,
 * and the two are different objects rather than an empty list that a
 * `count() === 0` somewhere could read as "no filter". That mistake has
 * exactly one outcome and it is the leak.
 */
final class Reach
{
    /**
     * @param list<string>|null $holders null for everything
     */
    private function __construct(private readonly ?array $holders)
    {
    }

    /**
     * Everything the organisation holds.
     *
     * The administrator's (`tenant.manage`), decided with the operator on
     * 2026-09-30: they already read every subscription on the organisation
     * screen, and an administrator who cannot see the work cannot take it
     * back when somebody leaves. No audit row: non-negotiable #21 traces
     * **staff** crossing into a customer's data, and this is the customer's
     * own administrator inside their own organisation.
     */
    public static function everything(): self
    {
        return new self(null);
    }

    /**
     * What these holders hold, and nothing else.
     *
     * @param list<string> $holders
     */
    public static function heldBy(array $holders): self
    {
        $unique = [];

        foreach ($holders as $holder) {
            if ($holder !== '' && !in_array($holder, $unique, true)) {
                $unique[] = $holder;
            }
        }

        return new self($unique);
    }

    /** Nothing at all: a caller on no subscription holds no project. */
    public static function nothing(): self
    {
        return new self([]);
    }

    public function isEverything(): bool
    {
        return $this->holders === null;
    }

    /** True when this reaches no project at all, whatever the tenant holds. */
    public function isNothing(): bool
    {
        return $this->holders === [];
    }

    /**
     * The holders, for a query that has already asked {@see isEverything()}.
     *
     * @return list<string>
     */
    public function holders(): array
    {
        return $this->holders ?? [];
    }

    public function includes(?string $holderUserId): bool
    {
        if ($this->holders === null) {
            return true;
        }

        // A project whose holder was erased (§30 sets the column null) is
        // reachable by administrators only. The safe direction: an
        // unreachable project is a support question, an over-shared one is a
        // leak.
        return $holderUserId !== null && in_array($holderUserId, $this->holders, true);
    }
}
