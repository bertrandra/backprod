<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Writing the two things an offer is built out of.
 *
 * {@see CatalogueRepository} reads plans and features and {@see
 * OfferAuthoringRepository} writes offers, and between them sat a hole nobody
 * had noticed: **nothing on this platform could create a plan or a feature.**
 * `INSERT INTO plans` appeared once in the whole repository, in the demo
 * seeder, and `POST /api/v1/plans` did not exist. An installation therefore
 * had a product, no plans, and no way to make one — so `POST /api/v1/offers`,
 * whose insert is `SELECT … FROM plans WHERE id = :planId`, could never match
 * anything. The catalogue was unreachable from its own first step.
 *
 * Product-scoped like every other catalogue port: an id alone reaching storage
 * unqualified is the shape ADR-015 forbids.
 *
 * **There is no delete.** Offers point at plans and entitlements point at
 * features, both by id and both in rows a subscription is priced from. What
 * a plan or a feature gets instead is a name that can be corrected and, for a
 * plan, a rank that can be reordered.
 */
interface CatalogueAdministration
{
    /**
     * Creates a plan.
     *
     * `rank` is what orders plans against each other, and it is the only
     * ordering that exists — an upgrade is a comparison of two integers, never
     * of two names (non-negotiable #25). Chosen by the caller rather than
     * derived from creation order, because "Pro sits above Starter" is a
     * commercial fact and not a fact about when somebody typed it in.
     *
     * @throws \App\Shared\Exceptions\ConflictException if the code is taken within this product
     */
    public function createPlan(string $productId, string $code, string $name, int $rank): Plan;

    /**
     * Renames a plan, reorders it, or both. Null leaves a field alone.
     *
     * The code is never touched: offers, documents and the API all name a plan
     * by it.
     */
    public function updatePlan(string $productId, string $planId, ?string $name, ?int $rank): ?Plan;

    /**
     * Creates a feature.
     *
     * `kind` is BOOLEAN or QUOTA and cannot change afterwards, because every
     * grant written against this feature was written meaning one or the other:
     * a quota's grant carries a limit and a boolean's carries null, and
     * flipping the kind would reinterpret rows that are already priced into
     * live subscriptions. A feature that should have been the other kind is a
     * new feature.
     *
     * `unit` is what a quota is counted in — "projects", "GB" — and the
     * database refuses one on a boolean.
     *
     * @throws \App\Shared\Exceptions\ConflictException if the code is taken within this product
     * @throws \App\Shared\Exceptions\BadRequestException if a boolean is given a unit
     */
    public function createFeature(
        string $productId,
        string $code,
        string $name,
        string $kind,
        ?string $unit,
    ): Feature;

    /**
     * Corrects what a feature is called, in every language it is called
     * something.
     *
     * Neither its code nor its kind may change: the code is how grants and
     * entitlements name it, and the kind decides how every grant already
     * written against it is read.
     *
     * `$translations` absent leaves them alone; present replaces the set,
     * so a language removed in the console is removed here. The English
     * stays on the feature itself — it is the key and the fallback
     * (ADR-050, `docs/translatable-fields-spec.md`).
     *
     * @param bool                                                        $setDescription whether `$description` is to be written — null is a value, so absence needs its own flag
     * @param array<string, array{name?: ?string, description?: ?string}>|null $translations
     */
    public function renameFeature(
        string $productId,
        string $featureId,
        string $name,
        bool $setDescription = false,
        ?string $description = null,
        ?array $translations = null,
    ): ?Feature;
}
