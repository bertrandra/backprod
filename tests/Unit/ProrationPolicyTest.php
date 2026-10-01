<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\ChangeDecision;
use App\Commerce\Domain\CollectedPeriod;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\ProrationPolicy;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionTerms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The proration arithmetic of spec §3, stated in exact numbers.
 *
 * It lives here rather than against the database because the interesting part
 * is a calculation, and a calculation asserted through three clocks and a
 * transaction is a calculation nobody can read. What the database has to do
 * with it — the anchor reset, the document, the refund — is
 * `SubscriptionLifecycleTest` and `ProratedUpgradeTest`.
 *
 * Every figure below is integer minor units. That is the rule (§4, §25) and it
 * is also what makes these assertions exact rather than approximate.
 */
#[CoversClass(ProrationPolicy::class)]
#[CoversClass(ChangeDecision::class)]
#[CoversClass(CollectedPeriod::class)]
final class ProrationPolicyTest extends TestCase
{
    /** A thirty-day period, so a day is a thirtieth and the sums are readable. */
    private const START = '2026-09-01 00:00:00';
    private const END = '2026-10-01 00:00:00';

    // --- the share of the period that has not been consumed -------------------

    /**
     * Ten days into a thirty-day month, twenty days are left: two thirds of
     * what was collected.
     */
    public function testTheCreditIsTheUnconsumedShareOfWhatWasCollected(): void
    {
        $decision = $this->immediate(collected: 3_000, on: '2026-09-11 00:00:00');

        self::assertTrue($decision->accepted);
        self::assertSame(ProrationPolicy::PRORATED, $decision->ruleId);
        self::assertSame(2_000, $decision->creditMinorUnits);
    }

    /**
     * **It rounds down**, deliberately and by at most one minor unit.
     *
     * 1,000 minor units over a thirty-day period, one day in: 29/30 is
     * 966.66…, and the platform returns 966. Rounding a credit *up* returns
     * more than was unconsumed, and on a chain of upgrades it would do so
     * repeatedly.
     */
    public function testTheCreditRoundsDownRatherThanReturningMoreThanWasUnconsumed(): void
    {
        $decision = $this->immediate(collected: 1_000, on: '2026-09-02 00:00:00');

        self::assertSame(966, $decision->creditMinorUnits);
    }

    /**
     * Spec §3.4, in the three dates the specification names.
     *
     * Upgrade on the 1st, the 5th and the 10th. Each move resets the anchor, so
     * the second change prorates the period the first one opened and the third
     * prorates the period the second one opened — never the original one. That
     * is what "the chain works by construction" means, and the numbers are what
     * say it: a platform that kept prorating the September period would credit
     * the 1st→5th slice twice over.
     */
    public function testSuccessiveChangesProrateThePeriodTheLastOneOpened(): void
    {
        // The 1st: a fresh thirty-day period, €30.00 collected for it.
        $first = $this->immediate(
            collected: 3_000,
            on: '2026-09-01 00:00:00',
            start: '2026-09-01 00:00:00',
            end: '2026-10-01 00:00:00',
        );

        // Nothing consumed yet, so all of it comes back.
        self::assertSame(3_000, $first->creditMinorUnits);
        self::assertSame('2026-10-01', $first->newPeriodEnd?->format('Y-m-d'));

        // The 5th, against the period the 1st opened — 1 Sep to 1 Oct — of
        // which four days are gone. 26/30 of €30.00 is €26.00.
        $second = $this->immediate(
            collected: 3_000,
            on: '2026-09-05 00:00:00',
            start: '2026-09-01 00:00:00',
            end: '2026-10-01 00:00:00',
        );

        self::assertSame(2_600, $second->creditMinorUnits);

        // The 10th, against the period the 5th opened — 5 Sep to 5 Oct, thirty
        // days — of which five are gone. 25/30 of €30.00 is €25.00.
        //
        // Read against the *original* September period instead and the answer
        // would be €21.00: the difference is exactly the slice the 5th already
        // credited, charged to the customer twice.
        $third = $this->immediate(
            collected: 3_000,
            on: '2026-09-10 00:00:00',
            start: '2026-09-05 00:00:00',
            end: '2026-10-05 00:00:00',
        );

        self::assertSame(2_500, $third->creditMinorUnits);
        self::assertNotSame(2_100, $third->creditMinorUnits);
    }

    public function testAPeriodAlreadyOverHasNothingLeftToCredit(): void
    {
        $decision = $this->immediate(collected: 3_000, on: '2026-10-02 00:00:00');

        self::assertSame(0, $decision->creditMinorUnits);
    }

    // --- what bounds the credit ----------------------------------------------

    /**
     * Nothing collected is an **answer**, not an error: a period nobody paid
     * for has no unconsumed value, and the reason travels with the decision so
     * the screen says it rather than showing a silent zero.
     */
    public function testAPeriodNobodyPaidForCreditsNothingAndSaysWhy(): void
    {
        $decision = $this->immediate(
            on: '2026-09-11 00:00:00',
            collectedPeriod: CollectedPeriod::nothing('EUR', 'Nothing has been collected for the current period.'),
        );

        self::assertTrue($decision->accepted, 'a move up is not refused for want of a credit');
        self::assertSame(0, $decision->creditMinorUnits);
        self::assertContains('Nothing has been collected for the current period.', $decision->reasons);
    }

    /**
     * The credit cannot exceed what is left to give back, and it says that it
     * was bounded.
     *
     * Two thirds of €30.00 is €20.00, but only €5.00 of that payment has not
     * already been returned or credited. Asking for more would be refused by
     * `REFUND_EXCEEDS_PAYMENT` or `CREDIT_EXCEEDS_INVOICE` — after the money
     * had been asked for, which is too late.
     */
    public function testTheCreditIsBoundedByWhatIsStillReturnable(): void
    {
        $decision = $this->immediate(
            on: '2026-09-11 00:00:00',
            collectedPeriod: CollectedPeriod::of('payment', 3_000, 500, 'EUR'),
        );

        self::assertSame(500, $decision->creditMinorUnits);
        self::assertContains(
            'Part of what was collected for this period has already been given back, which bounds the credit.',
            $decision->reasons,
        );
    }

    // --- the net, and the document it is not ---------------------------------

    /**
     * `net` is the one place charge − credit is worked out. A screen doing it
     * would be adding two amounts in the frontend, which §4 forbids.
     */
    public function testTheNetIsTheChargeLessTheCredit(): void
    {
        $decision = $this->immediate(collected: 3_000, on: '2026-09-11 00:00:00', charge: 4_680);

        self::assertSame(4_680, $decision->chargeMinorUnits);
        self::assertSame(2_000, $decision->creditMinorUnits);
        self::assertSame(2_680, $decision->netMinorUnits);
    }

    /**
     * And it may be **negative**: rank is not price, so a credit can be the
     * larger of the two. Reporting it as zero would hide money coming back.
     */
    public function testTheNetIsNegativeWhenTheCreditIsLarger(): void
    {
        $decision = $this->immediate(collected: 12_000, on: '2026-09-11 00:00:00', charge: 3_900);

        self::assertSame(8_000, $decision->creditMinorUnits);
        self::assertSame(-4_100, $decision->netMinorUnits);
    }

    /**
     * Nothing outstanding raises no document, and the decision says so —
     * because numbering is gapless and a €0 invoice is the permanent record of
     * no transaction.
     */
    public function testNothingOutstandingSaysNoInvoiceIsRaised(): void
    {
        $decision = $this->immediate(collected: 0, on: '2026-09-11 00:00:00', charge: 0);

        self::assertSame(0, $decision->chargeMinorUnits);
        self::assertStringContainsString('no invoice is raised', implode(' ', $decision->reasons));
    }

    // --- what it refuses -----------------------------------------------------

    /**
     * A CUSTOM billing period has no computable length, so there is no
     * fraction of it to take. Dividing by something meaningless would produce
     * a credit nobody can explain, so it refuses and says which.
     */
    public function testACustomPeriodBeingLeftCannotBeProrated(): void
    {
        $decision = $this->immediate(collected: 3_000, on: '2026-09-11 00:00:00', heldPeriod: 'CUSTOM');

        self::assertFalse($decision->accepted);
        self::assertSame(ProrationPolicy::NOT_PRICEABLE, $decision->ruleId);
        self::assertSame(0, $decision->creditMinorUnits);
        self::assertStringContainsString('no computable period end', implode(' ', $decision->reasons));
    }

    /**
     * But a subscription with a **period end** is prorated even when its terms
     * name no billing period — which is the shape of a free period (spec §6.3),
     * sold on `CUSTOM` terms and given an end when it is taken out.
     *
     * The guard asks the subscription's own dates and not the word `CUSTOM`, and
     * this is why: refusing on the word would make the one upgrade a free tier
     * exists to produce impossible. Nothing was collected for a free period, so
     * nothing is credited — and the whole of the arriving plan is payable.
     */
    public function testAFreePeriodWithAnEndIsProratedDespiteHavingNoBillingPeriod(): void
    {
        $decision = (new ProrationPolicy())->immediate(
            $this->subscription('CUSTOM', commitmentMonths: 0, periodEnd: self::END),
            $this->offer('MONTHLY', 500),
            'UPGRADE',
            CollectedPeriod::nothing('EUR', 'Nothing has been collected for the current period.'),
            500,
            new DateTimeImmutable('2026-09-11 00:00:00'),
        );

        self::assertTrue($decision->accepted, 'leaving a free period for a paid plan is an ordinary move up');
        self::assertSame(ProrationPolicy::PRORATED, $decision->ruleId);
        self::assertSame(0, $decision->creditMinorUnits, 'nothing was collected for it');
        self::assertSame(500, $decision->netMinorUnits, 'and the whole of the new period is payable');
        self::assertSame('2026-10-11', $decision->newPeriodEnd?->format('Y-m-d'));
    }

    /** And a CUSTOM period being moved *to* has no new period to start. */
    public function testACustomPeriodBeingMovedToCannotBeProratedEither(): void
    {
        $decision = $this->immediate(collected: 3_000, on: '2026-09-11 00:00:00', arrivingPeriod: 'CUSTOM');

        self::assertFalse($decision->accepted);
        self::assertSame(ProrationPolicy::NOT_PRICEABLE, $decision->ruleId);
        self::assertStringContainsString('no computable billing period', implode(' ', $decision->reasons));
    }

    /**
     * A credit in one currency cannot be set against a charge in another, and
     * the only honest exchange rate is one nobody has supplied.
     */
    public function testACreditInAnotherCurrencyIsRefusedRatherThanNetted(): void
    {
        $decision = $this->immediate(
            on: '2026-09-11 00:00:00',
            collectedPeriod: CollectedPeriod::of('payment', 3_000, 3_000, 'USD'),
        );

        self::assertFalse($decision->accepted);
        self::assertSame(ProrationPolicy::CURRENCY_MISMATCH, $decision->ruleId);
    }

    // --- a move down ---------------------------------------------------------

    /**
     * A move down changes nothing today: no credit, no charge, and the date it
     * takes effect is the end of the period already paid for.
     */
    public function testAMoveDownCostsNothingAndWaitsForThePaidPeriod(): void
    {
        $decision = (new ProrationPolicy())->deferred(
            $this->subscription('MONTHLY'),
            $this->offer('MONTHLY', 500),
            'DOWNGRADE',
            new DateTimeImmutable('2026-09-11 00:00:00'),
        );

        self::assertTrue($decision->accepted);
        self::assertSame(ProrationPolicy::DEFERRED, $decision->ruleId);
        self::assertSame(ChangeDecision::AT_PERIOD_END, $decision->effect);
        self::assertSame(self::END, $decision->effectiveAt?->format('Y-m-d H:i:s'));
        self::assertSame(0, $decision->creditMinorUnits);
        self::assertSame(0, $decision->chargeMinorUnits);
        // The period the lower plan will open, counted from the date it starts
        // — not from today.
        self::assertSame('2026-11-01', $decision->newPeriodEnd?->format('Y-m-d'));
    }

    public function testAMoveDownWithNoPeriodEndHasNoDateToWaitFor(): void
    {
        $decision = (new ProrationPolicy())->deferred(
            $this->subscription('CUSTOM'),
            $this->offer('MONTHLY', 500),
            'DOWNGRADE',
            new DateTimeImmutable('2026-09-11 00:00:00'),
        );

        self::assertFalse($decision->accepted);
        self::assertSame(ProrationPolicy::NOT_PRICEABLE, $decision->ruleId);
    }

    // --- the commitment the reset does not touch ------------------------------

    /**
     * §3.3: resetting the billing anchor neither re-arms the commitment nor
     * shortens it. The decision reports the date the customer agreed to,
     * because "does upgrading re-commit me?" is what somebody under commitment
     * actually asks.
     */
    public function testTheCommitmentReportedIsTheOneAlreadyAgreed(): void
    {
        $agreed = new DateTimeImmutable('2028-01-01 00:00:00');

        $decision = (new ProrationPolicy())->immediate(
            $this->subscription('MONTHLY', commitmentMonths: 24, commitmentEndsAt: $agreed),
            // The arriving offer sells twelve months, which would end sooner.
            $this->offer('MONTHLY', 3_900, commitmentMonths: 12),
            'UPGRADE',
            CollectedPeriod::of('payment', 3_000, 3_000, 'EUR'),
            3_900,
            new DateTimeImmutable('2026-09-11 00:00:00'),
        );

        self::assertSame($agreed->format(DATE_ATOM), $decision->commitmentEndsAt?->format(DATE_ATOM));
    }

    // --- builders -------------------------------------------------------------

    private function immediate(
        string $on = self::START,
        int $collected = 0,
        int $charge = 3_900,
        string $heldPeriod = 'MONTHLY',
        string $arrivingPeriod = 'MONTHLY',
        string $start = self::START,
        string $end = self::END,
        ?CollectedPeriod $collectedPeriod = null,
    ): ChangeDecision {
        return (new ProrationPolicy())->immediate(
            $this->subscription($heldPeriod, $start, $end),
            $this->offer($arrivingPeriod, $charge),
            'UPGRADE',
            $collectedPeriod ?? CollectedPeriod::of('payment', $collected, $collected, 'EUR'),
            $charge,
            new DateTimeImmutable($on),
        );
    }

    /**
     * @param string|null $periodEnd overrides what the billing period implies —
     *                               the shape of a free period, which has an end
     *                               although its terms name no period (spec §6.3)
     */
    private function subscription(
        string $billingPeriod,
        string $start = self::START,
        string $end = self::END,
        int $commitmentMonths = 0,
        ?DateTimeImmutable $commitmentEndsAt = null,
        ?string $periodEnd = null,
    ): Subscription {
        $terms = new SubscriptionTerms(
            null,
            $commitmentMonths,
            SubscriptionTerms::ANYTIME,
            SubscriptionTerms::AUTO_RENEW,
            SubscriptionTerms::FORBIDDEN,
            0,
        );

        return new Subscription(
            'subscription',
            'tenant',
            'product',
            new SubscribedOffer(
                'offer',
                'starter',
                'Starter',
                new Plan('plan', 'STARTER', 'Starter', 10),
                $this->version($billingPeriod, 1_000, 0),
            ),
            'user-1',
            $terms,
            Subscription::ACTIVE,
            new DateTimeImmutable($start),
            new DateTimeImmutable($start),
            // A CUSTOM period has no computable end, so the subscription
            // ordinarily has none either — unless the caller gives it one, which
            // is what a free period does.
            $periodEnd !== null
                ? new DateTimeImmutable($periodEnd)
                : ($billingPeriod === 'CUSTOM' ? null : new DateTimeImmutable($end)),
            false,
            null,
            null,
            null,
            $commitmentEndsAt,
        );
    }

    private function offer(string $billingPeriod, int $price, int $commitmentMonths = 0): SubscribedOffer
    {
        return new SubscribedOffer(
            'arriving',
            'pro',
            'Pro',
            new Plan('plan-pro', 'PRO', 'Pro', 20),
            $this->version($billingPeriod, $price, $commitmentMonths),
        );
    }

    private function version(string $billingPeriod, int $price, int $commitmentMonths): OfferVersion
    {
        return new OfferVersion(
            'version-' . $billingPeriod . '-' . $price,
            1,
            OfferVersion::ACTIVE,
            $billingPeriod,
            $price,
            'EUR',
            new DateTimeImmutable(self::START),
            null,
            [],
            new SubscriptionTerms(
                null,
                $commitmentMonths,
                SubscriptionTerms::ANYTIME,
                SubscriptionTerms::AUTO_RENEW,
                SubscriptionTerms::FORBIDDEN,
                0,
            ),
        );
    }
}
