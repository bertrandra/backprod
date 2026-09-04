<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\CancellationPolicy;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionTerms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §13.1's commitment rules. What the policy *refuses to release* matters more
 * than what it releases, so those come first (§37.4).
 */
#[CoversClass(CancellationPolicy::class)]
#[CoversClass(CancellationDecision::class)]
#[CoversClass(SubscriptionTerms::class)]
#[CoversClass(Subscriber::class)]
final class CancellationPolicyTest extends TestCase
{
    private const NOW = '2026-09-04';

    // --- what a commitment must not allow --------------------------------

    public function testCancellingAtMonthTwoOfATwelveMonthCommitmentIsDeferred(): void
    {
        $decision = $this->decide(24, 12, SubscriptionTerms::AT_COMMITMENT_END, monthsIn: 2);

        // The exit criterion of M5.1: it does not just refuse, it says when
        // the customer may actually leave.
        self::assertTrue($decision->accepted);
        self::assertSame(CancellationDecision::AT_COMMITMENT_END, $decision->effect);
        self::assertNotNull($decision->effectiveAt);
        // Started two months ago on the 4th of July; twelve months on.
        self::assertSame('2027-07-04', $decision->effectiveAt->format('Y-m-d'));
    }

    public function testAnImmediateExitUnderAForbiddenCommitmentIsDeferredNotGranted(): void
    {
        $decision = $this->decide(
            24,
            12,
            SubscriptionTerms::AT_COMMITMENT_END,
            SubscriptionTerms::FORBIDDEN,
            monthsIn: 2,
            immediately: true,
        );

        // Asking for it now does not make it now.
        self::assertNotSame(CancellationDecision::IMMEDIATE, $decision->effect);
        self::assertSame(CancellationDecision::AT_COMMITMENT_END, $decision->effect);
    }

    public function testAtTermDefersToTheTermNotTheCommitment(): void
    {
        $decision = $this->decide(24, 12, SubscriptionTerms::AT_TERM, monthsIn: 2);

        self::assertSame(CancellationDecision::AT_TERM, $decision->effect);
        self::assertNotNull($decision->effectiveAt);
        // Two years from the start, not one.
        self::assertSame('2028-07-04', $decision->effectiveAt->format('Y-m-d'));
    }

    public function testAnUnknownPolicyIsRefusedRatherThanGuessed(): void
    {
        $decision = $this->decide(24, 12, 'WHENEVER_I_LIKE', monthsIn: 2);

        // Guessing would either trap a customer who may leave or release one
        // who may not.
        self::assertFalse($decision->accepted);
        self::assertSame('cancel.unknown_policy', $decision->ruleId);
    }

    // --- what it must allow ----------------------------------------------

    public function testOnceTheCommitmentHasPassedItEndsWithThePaidPeriod(): void
    {
        $decision = $this->decide(24, 12, SubscriptionTerms::AT_COMMITMENT_END, monthsIn: 14);

        // The clock released it. Nothing had to run.
        self::assertSame(CancellationDecision::AT_PERIOD_END, $decision->effect);
    }

    public function testAnytimeMeansTheCommitmentBindsThePriceNotTheExit(): void
    {
        $decision = $this->decide(24, 12, SubscriptionTerms::ANYTIME, monthsIn: 2);

        self::assertSame(CancellationDecision::AT_PERIOD_END, $decision->effect);
    }

    public function testAMonthToMonthSubscriptionEndsWithThePaidPeriod(): void
    {
        $decision = $this->decide(null, 0, SubscriptionTerms::ANYTIME, monthsIn: 2);

        // The service is owed until the end of what was paid for — M5's rule,
        // unchanged.
        self::assertSame(CancellationDecision::AT_PERIOD_END, $decision->effect);
    }

    public function testEarlyTerminationChargesTheMonthsThatRemain(): void
    {
        $decision = $this->decide(
            24,
            12,
            SubscriptionTerms::AT_COMMITMENT_END,
            SubscriptionTerms::CHARGE_REMAINING,
            monthsIn: 2,
            immediately: true,
        );

        self::assertSame(CancellationDecision::IMMEDIATE, $decision->effect);
        // Ten of the twelve remain.
        self::assertSame(10, $decision->chargeableMonths);
    }

    public function testAFreeEarlyExitChargesNothing(): void
    {
        $decision = $this->decide(
            24,
            12,
            SubscriptionTerms::AT_COMMITMENT_END,
            SubscriptionTerms::FREE,
            monthsIn: 2,
            immediately: true,
        );

        self::assertSame(CancellationDecision::IMMEDIATE, $decision->effect);
        self::assertSame(0, $decision->chargeableMonths);
    }

    // --- the decision is an explanation, not a flag ----------------------

    public function testEveryDecisionSaysWhichRuleDecidedAndWhy(): void
    {
        $decision = $this->decide(24, 12, SubscriptionTerms::AT_COMMITMENT_END, monthsIn: 2);

        self::assertSame('cancel.deferred_to_commitment_end', $decision->ruleId);
        self::assertNotSame([], $decision->reasons);
        self::assertStringContainsString('commitment is in force', implode(' ', $decision->reasons));
    }

    // --- the durations are not each other --------------------------------

    public function testThePaymentPeriodIsNotTheCommitment(): void
    {
        $terms = new SubscriptionTerms(
            24,
            12,
            SubscriptionTerms::AT_COMMITMENT_END,
            SubscriptionTerms::AUTO_RENEW,
            SubscriptionTerms::FORBIDDEN,
            0,
        );

        $start = new DateTimeImmutable('2026-01-01');

        // Billed monthly, committed for a year, running for two. Three
        // different answers to three different questions — which is the whole
        // point of §13.1.
        self::assertSame('2027-01-01', $terms->commitmentEndsFrom($start)?->format('Y-m-d'));
        self::assertSame('2028-01-01', $terms->termEndsFrom($start)?->format('Y-m-d'));
    }

    public function testOpenEndedTermsCommitToNothing(): void
    {
        $terms = SubscriptionTerms::openEnded();
        $start = new DateTimeImmutable('2026-01-01');

        self::assertFalse($terms->hasCommitment());
        self::assertNull($terms->commitmentEndsFrom($start));
        self::assertNull($terms->termEndsFrom($start));
    }

    // --- a seat entitles one person --------------------------------------

    public function testATenantSubscriptionEntitlesEveryone(): void
    {
        self::assertTrue(Subscriber::tenant()->entitles('anyone'));
    }

    public function testASeatEntitlesOnlyItsHolder(): void
    {
        $seat = Subscriber::user('alice');

        self::assertTrue($seat->entitles('alice'));
        self::assertFalse($seat->entitles('bob'));
    }

    // --- Terms with no computable period end ---------------------------------

    /**
     * A CUSTOM billing period is negotiated, not computed, so there is no
     * date on which "at the end of the paid period" falls. Answering "today"
     * would forfeit a period agreed precisely because it does not fit a
     * month; answering "never" would leave a subscription the platform calls
     * cancelled running forever. It refuses and says which.
     */
    public function testTermsWithNoPeriodEndCannotBeCancelledOnADate(): void
    {
        $decision = $this->decide(null, 0, SubscriptionTerms::ANYTIME, billingPeriod: 'CUSTOM');

        self::assertFalse($decision->accepted);
        self::assertSame('cancel.period_end_unknown', $decision->ruleId);
        self::assertNull($decision->effect);
    }

    public function testTheSameTermsCanStillBeEndedImmediately(): void
    {
        $decision = $this->decide(
            null,
            0,
            SubscriptionTerms::ANYTIME,
            immediately: true,
            billingPeriod: 'CUSTOM',
        );

        self::assertTrue($decision->accepted);
        self::assertSame(CancellationDecision::IMMEDIATE, $decision->effect);
    }

    /**
     * The refusal is scoped to the rules that need a *period* end. A
     * commitment date is a real date that does not come from the period, so
     * it still decides.
     */
    public function testACommitmentDateStillDecidesWithoutAPeriodEnd(): void
    {
        $decision = $this->decide(
            null,
            12,
            SubscriptionTerms::AT_COMMITMENT_END,
            monthsIn: 2,
            billingPeriod: 'CUSTOM',
        );

        self::assertTrue($decision->accepted);
        self::assertSame('cancel.deferred_to_commitment_end', $decision->ruleId);
        self::assertSame(CancellationDecision::AT_COMMITMENT_END, $decision->effect);
    }

    /**
     * And "cancellable at any time" is one of the rules that needs it: it
     * ends with the paid period, and there is no paid period to end with.
     */
    public function testAnytimeUnderCommitmentAlsoNeedsAPeriodEnd(): void
    {
        $decision = $this->decide(
            null,
            12,
            SubscriptionTerms::ANYTIME,
            monthsIn: 2,
            billingPeriod: 'CUSTOM',
        );

        self::assertFalse($decision->accepted);
        self::assertSame('cancel.period_end_unknown', $decision->ruleId);
    }

    private function decide(
        ?int $termMonths,
        int $commitmentMonths,
        string $policy,
        string $earlyTermination = SubscriptionTerms::FORBIDDEN,
        int $monthsIn = 0,
        bool $immediately = false,
        string $billingPeriod = 'MONTHLY',
    ): CancellationDecision {
        $now = new DateTimeImmutable(self::NOW);
        $started = $now->modify(sprintf('-%d months', $monthsIn));

        $terms = new SubscriptionTerms(
            $termMonths,
            $commitmentMonths,
            $policy,
            SubscriptionTerms::AUTO_RENEW,
            $earlyTermination,
            0,
        );

        $version = new OfferVersion(
            'version',
            1,
            OfferVersion::ACTIVE,
            $billingPeriod,
            2900,
            'EUR',
            $started,
            null,
            [],
            $terms,
        );

        $subscription = new Subscription(
            'subscription',
            'tenant',
            'product',
            new SubscribedOffer('offer', 'pro', 'Pro', new Plan('plan', 'PRO', 'Pro', 10), $version),
            Subscriber::tenant(),
            $terms,
            Subscription::ACTIVE,
            $started,
            $now->modify('-3 days'),
            // A CUSTOM period has no computable end, so the subscription has
            // none either — which is the whole point of these cases.
            $billingPeriod === 'CUSTOM' ? null : $now->modify('+27 days'),
            false,
            null,
            null,
            $terms->termEndsFrom($started),
            $terms->commitmentEndsFrom($started),
            null,
        );

        return (new CancellationPolicy())->decide($subscription, $now, $immediately);
    }
}
