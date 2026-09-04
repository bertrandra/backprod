<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * Subscriptions and the history behind them.
 *
 * Every method is scoped by tenant and product, because a subscription is
 * tenant data and the caller's context is the only sanctioned source of both
 * (ADR-015).
 *
 * The write methods take a Subscription rather than an id, for the same
 * reason the project repository does: a Subscription can only come from a
 * scoped read, so there is no method here that can be called with an id
 * somebody guessed.
 */
interface SubscriptionRepository
{
    /**
     * The tenant's live subscription for a product, if it has one.
     *
     * "Live" is not the same as "the most recent row": a cancelled or
     * expired subscription is still stored, and is returned by history()
     * rather than here.
     */
    public function findActive(string $tenantId, string $productId): ?Subscription;

    /**
     * Every subscription the tenant has held for a product, newest first.
     *
     * @return list<Subscription>
     */
    public function history(string $tenantId, string $productId): array;

    /**
     * Starts a subscription and records its activation, in one transaction.
     *
     * Implementations must also write the entitlements the offer version
     * grants. A subscription that exists without them would leave the tenant
     * paying for capabilities they cannot use, and the two must not be
     * observable apart.
     */
    public function activate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): Subscription;

    /**
     * The same activation, without a transaction of its own, for a caller
     * that already has one open on the same connection.
     *
     * An order fulfilling itself starts the subscription, raises the invoice
     * and completes, and those three cannot be observed apart: the schema
     * refuses a completed order that does not name both.
     */
    public function applyActivate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): Subscription;

    /**
     * Moves a live subscription onto different terms, keeping its period.
     *
     * Prorating the difference is billing, and billing is M6. What happens
     * here is the change of what the tenant may use, recorded with both ends
     * so the move is auditable.
     */
    public function changeOffer(
        Subscription $subscription,
        SubscribedOffer $offer,
        string $direction,
        ?string $actorUserId,
    ): Subscription;

    /**
     * Schedules the end of a subscription, or ends it now.
     *
     * Scheduled is the default because a customer who cancels on day two of
     * a month they paid for keeps the month; immediate cancellation ends the
     * entitlements with it.
     */
    public function cancel(Subscription $subscription, bool $immediately, ?string $actorUserId): Subscription;

    /**
     * Withdraws a scheduled cancellation. Only meaningful while the
     * subscription is still live — one that has already ended is restarted
     * by subscribing again, not by resuming.
     */
    public function resume(Subscription $subscription, ?string $actorUserId): Subscription;

    /**
     * Advances a subscription into its next period and extends the
     * entitlements to match.
     *
     * There is no endpoint for this. Renewal is something time does, and the
     * job that notices is M7; this exists so the behaviour is written and
     * tested rather than waiting on a scheduler.
     */
    public function renew(Subscription $subscription, ?DateTimeImmutable $periodEnd): Subscription;

    /**
     * @return list<SubscriptionEvent>
     */
    public function events(Subscription $subscription): array;

    /**
     * Moves subscriptions past the end of their period to EXPIRED, and says
     * how many moved.
     *
     * Entitlements already ask the clock, so this changes nothing a customer
     * can do — it makes the column agree with reality for the people reading
     * it. A subscription with no period end has no end to be past.
     */
    public function expireLapsed(): int;
}
