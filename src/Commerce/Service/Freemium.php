<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\FreemiumPeriod;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionRepository;
use App\Product\Domain\ProductSettings;
use App\Shared\Exceptions\ConflictException;
use DateTimeImmutable;

/**
 * Taking the free period (spec §6).
 *
 * **Why this is not `openCheckoutSession`.** The price is zero, and "nothing
 * outstanding raises no document at all": numbering is gapless, so a €0 invoice
 * is a permanent, unremovable record of no transaction, sitting in the middle
 * of a series a tax authority reads. The checkout chain exists to compose three
 * things — an order, its invoice, and a payment at the provider — and a
 * freemium has none of them. Going through it would raise that document and
 * then look for money that was never owed.
 *
 * **Why this is not the door ADR-056 closed.** `POST /api/v1/subscription`
 * existed, defaulted to the organisation, was gated on a permission every
 * member holds, and started a subscription with no invoice and no payment for
 * a **priced** offer — credit extended to anybody who could reach the
 * endpoint. Three separate faults, and this shares none of them:
 *
 *   - the subscriber is always the caller, a seat, taken from the resolved
 *     context (ADR-055) — there is no id in the body and so nothing to check
 *     one against;
 *   - the permission is `billing.pay`, the member's and not the
 *     administrator's, because taking out a subscription for oneself is the
 *     act that permission names;
 *   - and the offer must be **free and non-renewing**, refused otherwise. No
 *     money is waited for because none is owed, which leaves ADR-024 exactly
 *     where it was: nothing priced is ever provisioned before it is paid.
 *
 * **Its own service, and not a method on {@see Subscriptions}.** The rules here
 * are the freemium's own — free, once ever, its own clock, no document — and
 * the one thing it shares with subscribing is the refusal of a seat somebody
 * already holds, which it asks that service for rather than re-implementing.
 *
 * **Nothing here checks whether the account has already had one.** That is
 * `subscriptions_one_freemium_ever`, and deliberately: a `SELECT` then an
 * `INSERT` is not a rule, it is a race two simultaneous requests walk straight
 * through. The refusal is minted where the database refuses
 * ({@see \App\Commerce\Infrastructure\PostgresSubscriptionRepository::activate()}).
 */
final class Freemium
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Subscriptions $lifecycle,
        private readonly Catalogue $catalogue,
        private readonly ProductSettings $settings,
    ) {
    }

    /**
     * Whether this person's one free period for this product is spent (§6.4).
     *
     * **A read, and never the rule.** The rule is
     * `subscriptions_one_freemium_ever`, for the reason written above: a
     * `SELECT` then an `INSERT` is not a constraint. What this answers is a
     * different question — the catalogue's. §6.4 asks the screen to say the
     * free period is gone *before* the click, because the alternative is a
     * customer choosing it and meeting a 409; and the row that matters most,
     * "leaving *Lecture* downwards", is a **cancellation** for somebody whose
     * free period is spent, which is not a thing to discover afterwards.
     *
     * Nobody named has had nothing: a free period belongs to the person who
     * takes it, and a request with no caller has no free period to have spent
     * — the same reasoning that mints `FREEMIUM_NEEDS_A_PERSON` below.
     */
    public function alreadyTaken(string $productId, ?string $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->subscriptions->hasHadFreemium($productId, $userId);
    }

    /**
     * Starts the caller's free period on an offer that gives one.
     *
     * @throws ConflictException FREEMIUM_ALREADY_USED — this account has had
     *                           its one, whatever became of it (§6.4)
     */
    public function take(
        string $tenantId,
        string $productId,
        string $offerId,
        ?string $actorUserId,
    ): Subscription {
        if ($actorUserId === null) {
            // A freemium is a seat, and a seat is addressed to somebody. There
            // is no anonymous holder to give one to, and defaulting to the
            // organisation is the sale ADR-055 stopped making.
            throw new ConflictException(
                'FREEMIUM_NEEDS_A_PERSON',
                'A free period belongs to the person who takes it, and this request names nobody.',
            );
        }

        $offer = SubscribedOffer::from($this->catalogue->offerOnSale($productId, $offerId));

        if (!$offer->version->isFreemium()) {
            // Asked of the offer's properties — a price of zero and a renewal
            // that stops at the term — never of a plan's code. The refusal
            // matters in both directions: a priced offer taken through this
            // door would be given away, and a free offer that renews taken
            // through it would be a permanent subscription counted as somebody's
            // one free trial.
            throw new ConflictException(
                'NOT_A_FREEMIUM_OFFER',
                'This offer is not a free period: it is sold, or it renews. Buy it instead.',
                ['offer_id' => $offer->offerId],
            );
        }

        $period = FreemiumPeriod::fromConfiguration($this->settings->all($productId));

        if ($period === null) {
            // Fail closed. Guessing an interval would give away a length of
            // time nobody decided on, and there is no honest default: five
            // days is the demonstration's answer, not the platform's.
            throw new ConflictException(
                'FREEMIUM_NOT_OFFERED',
                'This product has not said how long its free period lasts, so it gives none.',
                ['product_id' => $productId],
            );
        }

        if ($this->lifecycle->seatOf($tenantId, $productId, $actorUserId) !== null) {
            // The same refusal buying one makes, in the same words, because it
            // is the same fact: somebody already inside the product does not
            // start again at the bottom, they change what they hold.
            throw new ConflictException(
                'SEAT_ALREADY_ACTIVE',
                'You already hold a live seat on this product.',
            );
        }

        // The period is written at subscription and never recomputed (§6.3):
        // one period, in days, with no term — so a freemium is an ordinary
        // subscription that happens to be over on a date, and nothing new had
        // to learn to count days. `periodEndFrom()` is deliberately not asked:
        // a freemium's billing period is CUSTOM, because it is never billed.
        return $this->subscriptions->activate(
            $tenantId,
            $productId,
            $offer,
            $period->endsFrom(new DateTimeImmutable()),
            $actorUserId,
            Subscriber::user($actorUserId),
        );
    }
}
