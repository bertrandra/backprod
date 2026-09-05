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
        ?Subscriber $subscriber = null,
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
        ?Subscriber $subscriber = null,
    ): Subscription;

    /**
     * One subscription by id, whatever its status or subscriber. A seat is not
     * reachable by (tenant, product) — that is the tenant's own.
     */
    public function findById(string $subscriptionId): ?Subscription;

    /**
     * Every live subscription entitling this person: the tenant's own, plus
     * their seat if they hold one.
     *
     * @return list<Subscription>
     */
    public function liveFor(string $tenantId, string $productId, string $userId): array;

    /**
     * Records a cancellation decision: the schedule flag, the effective date
     * the customer was told, and the event.
     *
     * The date is stored rather than recomputed on read, because "I
     * cancelled" against "we received nothing" needs an arbiter, and a
     * recomputation would answer with today's rules rather than the ones in
     * force when the request was made.
     *
     * $alsoCharge runs inside this method's transaction, after the row has
     * moved, for a decision that costs something. A subscription released
     * with its buy-out unbilled is revenue given away, and a buy-out billed
     * against a subscription still running is a customer charged for an exit
     * they did not get; neither may survive a crash between the two.
     *
     * @param (callable(Subscription): void)|null $alsoCharge
     */
    public function scheduleCancellation(
        Subscription $subscription,
        CancellationDecision $decision,
        ?string $actorUserId,
        ?callable $alsoCharge = null,
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

    /**
     * Subscriptions inside their pre-renewal notice window, and who to tell.
     *
     * §13.1's notice is a *deadline*, not a courtesy: a notice sent late is a
     * notice not sent, so the window is the question and `notice_days` before
     * `term_ends_at` is the answer. Past the term end nothing is returned —
     * whatever that would be, it is not prior notice.
     *
     * A subscription already set to end is excluded. There is no tacit
     * renewal to warn about when the customer has already said no, and
     * telling them otherwise would be worse than saying nothing.
     *
     * One row per recipient, so a tenant subscription with three
     * administrators comes back three times. The limit therefore bounds
     * *recipients*, not subscriptions, and a subscription whose
     * administrators do not all fit is finished on the next pass — the
     * notification's dedup key makes re-reading it free.
     *
     * @return list<RenewalNotice>
     */
    public function dueForRenewalNotice(int $limit): array;
}
