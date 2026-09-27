<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * What moving to another offer costs today, and when it takes effect
 * (spec §3, §4).
 *
 * The counterpart of {@see CancellationPolicy}: one object, asked by the
 * preview and by the act, so the figure a screen shows is the figure that is
 * charged. A preview computed anywhere else is a second answer to a question
 * that already has one, and the two disagree at exactly the boundaries that
 * matter.
 *
 * **The calculation is here, in the domain, in integer minor units.** Not in
 * React — "never add two amounts in the frontend; every total on screen is the
 * server's" (§4, §25) — and not in floating point, because the error does not
 * show in a test with two lines, it shows in an audit of a year of them.
 *
 * ## The three rules
 *
 * **A move up takes effect now and is priced.** The unconsumed part of the
 * current period becomes a credit, the new period is charged in full, and the
 * billing anchor restarts today. A move down waits for the end of the paid
 * period and costs nothing — the customer keeps what they bought.
 *
 * **A move sideways is priced like a move up**, because it happens now. Two
 * offers on the same rank are the same tier at possibly different prices, and
 * "immediate" is what decides whether there is a period to cut short — not
 * which direction the rank went. Treating a lateral move as free would leave
 * a hole shaped exactly like the one §1(b) describes.
 *
 * **The anchor reset is why successive upgrades need no credit balance**
 * (§3.4). The credit is read off `current_period_start` / `current_period_end`
 * as they stand at that moment, and every immediate change resets both — so
 * upgrading on the 1st, the 5th and the 10th prorates against the period
 * opened by the previous upgrade, by construction. Without the reset the
 * platform would have to carry a balance, and a balance is an object somebody
 * has to expire, refund and declare.
 *
 * ## What it refuses
 *
 * A period with **no end** has no fraction of it to take, and an offer with no
 * computable billing period has no new period to start. Dividing by something
 * meaningless would produce a credit nobody can explain, so the answer is a
 * refusal with its reason — the same choice
 * {@see CancellationPolicy::periodEndUnknown()} makes, and the same one
 * {@see OfferVersion::periodsIn()} makes about pricing a buy-out.
 *
 * **It asks the subscription's own dates, not the billing period's name**, and
 * that distinction is load-bearing. A free period is sold on `CUSTOM` terms and
 * given a `current_period_end` when it is taken out (spec §6.3), so leaving one
 * for a paid plan is an ordinary move up: there is an end to measure against,
 * the unconsumed value of something nobody paid for is zero, and nothing is
 * credited. Refusing on the word `CUSTOM` would make the one upgrade a free tier
 * exists to produce impossible.
 *
 * Two currencies are refused for the same kind of reason: a credit in one and
 * a charge in another cannot be netted, and the only honest exchange rate is
 * one nobody has supplied ({@see \App\Billing\Domain\Money}).
 */
final class ProrationPolicy
{
    public const PRORATED = 'change.prorated_now';
    public const DEFERRED = 'change.deferred_to_period_end';
    public const NOT_PRICEABLE = 'change.period_not_priceable';
    public const CURRENCY_MISMATCH = 'change.currency_mismatch';

    /**
     * A move that takes effect now: what is credited, what is charged, and
     * when the period it opens ends.
     */
    public function immediate(
        Subscription $subscription,
        SubscribedOffer $offer,
        string $direction,
        CollectedPeriod $collected,
        int $chargeGrossMinorUnits,
        DateTimeImmutable $now,
    ): ChangeDecision {
        $currency = $offer->version->currency;
        $periodEnd = $subscription->currentPeriodEnd;
        $newPeriodEnd = $offer->version->periodEndFrom($now);

        if ($periodEnd === null || $newPeriodEnd === null) {
            return ChangeDecision::refused(
                self::NOT_PRICEABLE,
                $direction,
                $currency,
                [
                    $periodEnd === null
                        ? 'These terms have no computable period end, so there is no unconsumed share of one to credit.'
                        : 'The offer being moved to has no computable billing period, so there is no new period to start.',
                    'A negotiated period is ended and restarted deliberately, not prorated.',
                ],
            );
        }

        if ($collected->collectedMinorUnits > 0 && $collected->currency !== $currency) {
            return ChangeDecision::refused(
                self::CURRENCY_MISMATCH,
                $direction,
                $currency,
                [
                    sprintf(
                        'The current period was paid in %s and this offer is priced in %s.',
                        $collected->currency,
                        $currency,
                    ),
                    'A credit in one currency cannot be set against a charge in another.',
                ],
            );
        }

        $unconsumed = self::unconsumed(
            $collected->collectedMinorUnits,
            $subscription->currentPeriodStart,
            $periodEnd,
            $now,
        );

        // Never more than is left to give back. The bound is the payment's and
        // the invoice's own, asked here rather than discovered when the money
        // is requested — a proration refused by `REFUND_EXCEEDS_PAYMENT` would
        // be a refusal arriving after the plan had already moved.
        $credit = min($unconsumed, $collected->returnableMinorUnits);

        return ChangeDecision::now(
            self::PRORATED,
            $direction,
            $now,
            $currency,
            $credit,
            $chargeGrossMinorUnits,
            $newPeriodEnd,
            // Unchanged by the reset, and reported so (§3.3).
            $subscription->commitmentAfterMovingTo($offer->version->terms, $now)->endsAt,
            self::whyImmediate($collected, $unconsumed, $credit, $chargeGrossMinorUnits),
        );
    }

    /**
     * A move down, which waits for the end of the period already paid for
     * (spec §4).
     */
    public function deferred(
        Subscription $subscription,
        SubscribedOffer $offer,
        string $direction,
        DateTimeImmutable $now,
    ): ChangeDecision {
        $currency = $offer->version->currency;
        $effectiveAt = $subscription->currentPeriodEnd;

        if ($effectiveAt === null) {
            // The same refusal `Subscriptions::defer()` makes when it is asked
            // to act, in the words a preview can render: there is no date to
            // defer to, and inventing one would move a customer off a plan on
            // a day nobody agreed.
            return ChangeDecision::refused(
                self::NOT_PRICEABLE,
                $direction,
                $currency,
                [
                    'These terms have no computable period end, so there is no date to defer a change to.',
                    'A negotiated period is ended and restarted deliberately.',
                ],
            );
        }

        return ChangeDecision::deferred(
            self::DEFERRED,
            $direction,
            $effectiveAt,
            $currency,
            $offer->version->periodEndFrom($effectiveAt),
            $subscription->commitmentAfterMovingTo($offer->version->terms, $now)->endsAt,
            [
                'A lower plan takes effect at the end of the period already paid for.',
                'Until then nothing changes, and nothing is charged or credited today.',
            ],
        );
    }

    /**
     * The unconsumed share of what was collected for a period.
     *
     * Time, not calendar months: a period's length is the distance between its
     * two ends, whatever the month it happens to span. Counting in days would
     * make February cheaper than March for the same money.
     *
     * **It rounds down**, deliberately and in the platform's favour by at most
     * one minor unit. The alternative rounds a credit *up*, which returns more
     * than was unconsumed — and on a chain of upgrades it would do so
     * repeatedly.
     *
     * A moment past the end gives nothing, and a moment before the start gives
     * everything: both are clamps rather than errors, because the clock is
     * read once by the caller and a subscription whose period has ended is
     * already not live.
     */
    public static function unconsumed(
        int $collectedMinorUnits,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeImmutable $now,
    ): int {
        if ($collectedMinorUnits <= 0) {
            return 0;
        }

        $total = $end->getTimestamp() - $start->getTimestamp();

        if ($total <= 0) {
            return 0;
        }

        $remaining = $end->getTimestamp() - $now->getTimestamp();

        if ($remaining <= 0) {
            return 0;
        }

        return intdiv($collectedMinorUnits * min($remaining, $total), $total);
    }

    /**
     * @return list<string>
     */
    private static function whyImmediate(
        CollectedPeriod $collected,
        int $unconsumed,
        int $credit,
        int $charge,
    ): array {
        $reasons = ['The new plan applies at once, and the billing period restarts today.'];

        if ($credit > 0) {
            $reasons[] = 'The unconsumed part of the current period goes back to the payment method that paid for it, with a credit note.';
        } elseif ($collected->whyNothing !== null) {
            $reasons[] = $collected->whyNothing;
        } else {
            $reasons[] = 'None of the current period is unconsumed, so there is nothing to credit.';
        }

        if ($credit < $unconsumed) {
            // Said, never silently swallowed: the customer is owed less than
            // the clock alone would suggest, and the reason is that part of
            // this period has already been given back.
            $reasons[] = 'Part of what was collected for this period has already been given back, which bounds the credit.';
        }

        $reasons[] = $charge > 0
            ? 'The new period is invoiced in full through the normal billing chain.'
            : 'Nothing is outstanding, so no invoice is raised — numbering is gapless, and a zero invoice would be a permanent record of no transaction.';

        return $reasons;
    }
}
