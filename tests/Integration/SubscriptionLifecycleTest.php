<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\Infrastructure\PostgresAuditLog;
use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\CancellationPolicy;
use App\Commerce\Domain\EarlyTerminationCharge;
use App\Commerce\Domain\Offer;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\ProrationPolicy;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Infrastructure\OfferVersionLoader;
use App\Commerce\Infrastructure\PostgresCatalogueRepository;
use App\Commerce\Infrastructure\PostgresEntitlementRepository;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Commerce\Service\Subscriptions;
use App\Shared\Database\Row;
use App\Shared\Exceptions\HttpException;
use App\Tests\Support\NothingWasCollected;
use App\Tests\Support\RecordingChangeCharge;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The subscription lifecycle against a real database.
 *
 * §37.4 names what a SaaS backend has to get right: activation, expiration,
 * renewal, upgrade, downgrade, cancellation and quota exhaustion. Every one
 * of them is here except quota exhaustion, which is exercised where it bites
 * — through the projects endpoint.
 *
 * All of it runs against PostgreSQL because the interesting behaviour is
 * transactional: a subscription, its event and its entitlements move
 * together or not at all, and entitlements lapse on a window the database
 * evaluates.
 */
#[CoversNothing]
final class SubscriptionLifecycleTest extends DatabaseTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $freeOffer = '';
    private string $proOffer = '';
    private string $projectsFeature = '';

    /** A higher plan that sells terms unlike anything the others sell. */
    private string $termsOffer = '';

    /** A two-year commitment, on the same rank as `pro`. */
    private string $longCommitmentOffer = '';

    /** A higher plan sold for six months only — shorter than that commitment. */
    private string $shortTermOffer = '';

    /** A plan ranked below `free`, so moving *up* to `free` costs nothing. */
    private string $belowFreeOffer = '';

    private RecordingChangeCharge $charge;

    private NothingWasCollected $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->charge = new RecordingChangeCharge();
        $this->credit = new NothingWasCollected();

        $this->product = $this->seedProduct('atlas');
        $this->tenant = $this->seedTenant('acme');
        $this->user = $this->seedUser('sub-alice');

        $free = $this->seedPlan('FREE', 10);
        $pro = $this->seedPlan('PRO', 20);

        $this->projectsFeature = $this->seedFeature('max_projects', 'QUOTA', 'projects');
        $advanced = $this->seedFeature('advanced_3d', 'BOOLEAN', null);

        $this->freeOffer = $this->seedOffer($free, 'free');
        $freeVersion = $this->seedVersion($this->freeOffer, 0, 'MONTHLY');
        $this->grant($freeVersion, $this->projectsFeature, 3);
        $this->publish($freeVersion);

        $this->proOffer = $this->seedOffer($pro, 'pro');
        $proVersion = $this->seedVersion($this->proOffer, 2900, 'MONTHLY');
        $this->grant($proVersion, $this->projectsFeature, 50);
        $this->grant($proVersion, $advanced, null);
        $this->publish($proVersion);

        // Three offers that exist to make the terms visible. Every condition
        // below differs from what `free` and `pro` sell, so a subscription
        // still carrying the old ones cannot pass by coincidence.
        $scale = $this->seedPlan('SCALE', 30);

        $this->termsOffer = $this->seedOffer($scale, 'scale-terms');
        $termsVersion = $this->seedVersion(
            $this->termsOffer,
            9900,
            'MONTHLY',
            termMonths: 24,
            commitmentMonths: 12,
            cancellationPolicy: 'AT_COMMITMENT_END',
            renewal: 'ENDS_AT_TERM',
            earlyTermination: 'CHARGE_REMAINING',
            noticeDays: 30,
        );
        $this->grant($termsVersion, $this->projectsFeature, 500);
        $this->publish($termsVersion);

        $this->longCommitmentOffer = $this->seedOffer($pro, 'pro-committed');
        $longVersion = $this->seedVersion(
            $this->longCommitmentOffer,
            2400,
            'MONTHLY',
            commitmentMonths: 24,
        );
        $this->grant($longVersion, $this->projectsFeature, 50);
        $this->publish($longVersion);

        $this->shortTermOffer = $this->seedOffer($scale, 'scale-six-months');
        $shortVersion = $this->seedVersion(
            $this->shortTermOffer,
            9900,
            'MONTHLY',
            termMonths: 6,
            commitmentMonths: 6,
        );
        $this->grant($shortVersion, $this->projectsFeature, 500);
        $this->publish($shortVersion);

        // A rank **below** `free`, which is priced at nothing: the pair that
        // makes "a move up that costs nothing raises no document" reachable
        // through the priced path rather than through a special case. It is
        // also the shape the freemium plan will have.
        $entry = $this->seedPlan('ENTRY', 5);
        $this->belowFreeOffer = $this->seedOffer($entry, 'entry');
        $entryVersion = $this->seedVersion($this->belowFreeOffer, 100, 'MONTHLY');
        $this->grant($entryVersion, $this->projectsFeature, 1);
        $this->publish($entryVersion);
    }

    // --- Activation ---------------------------------------------------------

    public function testActivationGrantsWhatTheOfferPromised(): void
    {
        $subscription = $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        );

        self::assertSame(Subscription::ACTIVE, $subscription->status);
        self::assertSame('pro', $subscription->offer->code);
        self::assertNotNull($subscription->currentPeriodEnd, 'a monthly offer has a period end');

        // The point of the milestone: capabilities now come from what was
        // bought, and the context chain reads exactly these.
        self::assertSame(
            ['advanced_3d', 'max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
        );

        $limits = $this->entitlements()->entitlementsFor($this->tenant, $this->product);
        self::assertSame(50, $limits[1]->limit);
        self::assertSame('SUBSCRIPTION', $limits[1]->source);
    }

    public function testActivationIsRecorded(): void
    {
        $this->subscribeToPro();

        $events = $this->subscriptions()->events($this->tenant, $this->product);

        self::assertCount(1, $events);
        self::assertSame(SubscriptionEvent::ACTIVATED, $events[0]->type);
        self::assertSame($this->user, $events[0]->actorUserId);
    }

    public function testATenantCannotHoldTwoSubscriptionsForOneProduct(): void
    {
        $this->subscribeToPro();

        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->freeOffer,
            $this->user,
        ));

        self::assertSame(409, $error->statusCode());
        self::assertSame('ALREADY_SUBSCRIBED', $error->errorCode());
    }

    /**
     * Buying goes through the catalogue, so an offer that is not for sale
     * cannot be subscribed to by naming its id.
     */
    public function testAWithdrawnOfferCannotBeSubscribedTo(): void
    {
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED' WHERE offer_id = :offer",
            ['offer' => $this->proOffer],
        );

        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        ));

        self::assertSame(404, $error->statusCode());
        self::assertSame('OFFER_NOT_FOUND', $error->errorCode());
    }

    // --- Expiration ---------------------------------------------------------

    /**
     * The milestone's central claim, and the reason entitlements carry a
     * window: nothing runs at midnight, and the tenant still stops being
     * entitled. The status column is deliberately left saying ACTIVE.
     */
    public function testEntitlementsLapseOnTheClockWithNothingSweepingThem(): void
    {
        $this->subscribeToPro();

        self::assertNotSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));

        // Move the period into the past, exactly as time passing would.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET current_period_start = now() - interval '2 months',
                       current_period_end = now() - interval '1 month'
                 WHERE tenant_id = :tenant
                SQL,
            ['tenant' => $this->tenant],
        );
        // Both ends move: valid_until must stay after valid_from, and a
        // lapsed entitlement is one whose whole window is in the past.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE entitlements
                   SET valid_from = now() - interval '2 months',
                       valid_until = now() - interval '1 month'
                 WHERE tenant_id = :tenant
                SQL,
            ['tenant' => $this->tenant],
        );

        self::assertSame(
            'ACTIVE',
            $this->statusOf(),
            'the row still says active — nothing has swept it',
        );
        self::assertSame(
            [],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'and the tenant is entitled to nothing anyway',
        );
        self::assertNull(
            $this->subscriptions()->current($this->tenant, $this->product),
            'and the service reports no live subscription',
        );
    }

    // --- Renewal ------------------------------------------------------------

    public function testRenewalExtendsThePeriodAndTheEntitlementsTogether(): void
    {
        $subscription = $this->subscribeToPro();
        $firstEnd = $subscription->currentPeriodEnd;
        self::assertNotNull($firstEnd);

        $renewed = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertNotNull($renewed->currentPeriodEnd);
        self::assertGreaterThan($firstEnd, $renewed->currentPeriodEnd);

        // The new period starts where the old one ended, so renewing late
        // leaves no gap the tenant was unentitled for.
        self::assertSame(
            $firstEnd->format(DATE_ATOM),
            $renewed->currentPeriodStart->format(DATE_ATOM),
        );

        // And the entitlements moved with it, rather than lapsing under a
        // subscription that is still being paid for.
        self::assertSame(
            ['advanced_3d', 'max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
        );
        self::assertSame(
            $renewed->currentPeriodEnd->format(DATE_ATOM),
            $this->entitlementEnd()?->format(DATE_ATOM),
        );
    }

    // --- Upgrade and downgrade ----------------------------------------------

    public function testAnUpgradeReplacesTheGrantsAndSaysWhichDirectionItWas(): void
    {
        $this->subscribeToFree();
        self::assertSame(3, $this->limitFor('max_projects'));

        $upgraded = $this->changeTo($this->proOffer);

        self::assertSame('pro', $upgraded->offer->code);
        self::assertSame(50, $this->limitFor('max_projects'));
        self::assertContains('advanced_3d', $this->entitlements()->capabilitiesFor($this->tenant, $this->product));

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::OFFER_CHANGED, $latest->type);
        self::assertSame(Subscriptions::UPGRADE, $latest->detail->direction ?? null);
    }

    // A downgrade used to take the extra grants away on the spot. It no
    // longer does, and that is the point of spec §4 — what it does instead is
    // `testADowngradeIsScheduledAndTakesNothingAway`, and what happens when
    // the date arrives is `testRenewalAppliesTheChangeThatHasComeDue`.

    /**
     * A move up **resets the billing anchor** (2026-09-27, spec §3).
     *
     * This asserted the opposite until today — "a change keeps the period the
     * tenant already paid for, prorating money is billing and billing is M6" —
     * and that was right while an upgrade was free. It is not any more: the
     * unconsumed part of the old period has gone back to the customer, so the
     * old period is over, and the new one starts now and runs for the arriving
     * offer's own billing period.
     *
     * It is also what makes a chain of upgrades need no credit balance (§3.4):
     * the next one prorates the period this one opened.
     */
    public function testAMoveUpRestartsThePeriodFromTheMomentOfTheChange(): void
    {
        $this->subscribeToFree();

        // Ten days into a thirty-day period, which is where an upgrade
        // actually happens. Backdated in SQL rather than waited for, and it is
        // also what makes the assertion below about the *change* rather than
        // about two clocks agreeing to the microsecond.
        $this->putThePeriodMidFlight();

        $after = $this->changeTo($this->proOffer);

        self::assertNotNull($after->currentPeriodEnd);

        // The old period is over: the new one starts now, not ten days ago.
        self::assertEqualsWithDelta(time(), $after->currentPeriodStart->getTimestamp(), 300);

        // And it runs a whole month from there — the arriving offer's own
        // billing period, not what was left of the old one.
        self::assertEqualsWithDelta(
            (new DateTimeImmutable('+1 month'))->getTimestamp(),
            $after->currentPeriodEnd->getTimestamp(),
            300,
        );

        // The entitlements follow the new period rather than lapsing with the
        // one that has just been credited back.
        self::assertSame(
            $after->currentPeriodEnd->format(DATE_ATOM),
            $this->entitlementEnd()?->format(DATE_ATOM),
        );
    }

    /**
     * Successive moves up chain **by construction** (spec §3.4).
     *
     * Each one opens a period and the next one is billed for the period *it*
     * opens, which is the whole reason the anchor resets: without it the
     * platform would have to carry a credit balance, and a balance is an object
     * somebody has to expire, refund and declare.
     *
     * What the arithmetic does to the credit is
     * {@see \App\Tests\Unit\ProrationPolicyTest}, where three dates can be
     * stated exactly. What this asserts is the property that makes it work —
     * every move bills its own period, and none of the three gets a free ride,
     * which is §1(b)'s defect.
     */
    public function testEveryMoveUpBillsThePeriodItOpens(): void
    {
        $this->subscribeToFree();
        $this->putThePeriodMidFlight();

        $second = $this->changeTo($this->proOffer);
        $this->putThePeriodMidFlight();
        $third = $this->changeTo($this->termsOffer);

        self::assertCount(2, $this->charge->charged);

        // Each document's period is the one its own change opened.
        self::assertSame(
            [
                $second->currentPeriodStart->format(DATE_ATOM),
                $third->currentPeriodStart->format(DATE_ATOM),
            ],
            array_column($this->charge->charged, 'periodStart'),
        );
        self::assertSame(
            [
                $second->currentPeriodEnd?->format(DATE_ATOM),
                $third->currentPeriodEnd?->format(DATE_ATOM),
            ],
            array_column($this->charge->charged, 'periodEnd'),
        );
    }

    /**
     * **Nothing outstanding raises no document at all.**
     *
     * `free` is priced at zero, so moving onto it from a plan of lower rank
     * would charge nothing — and a €0 invoice is not a cheap invoice, it is the
     * permanent, unremovable record of no transaction, because numbering is
     * gapless.
     *
     * Reached by rank rather than by price: `free` sits on rank 10 and the
     * offer moved from is below it, so this is a move *up* to something that
     * costs nothing. Which is exactly the freemium shape the catalogue will
     * sell, and the reason the rule has to hold on the priced path rather than
     * on a special case.
     */
    public function testAMoveUpThatCostsNothingRaisesNoInvoice(): void
    {
        $this->subscriptions()->subscribe($this->tenant, $this->product, $this->belowFreeOffer, $this->user);

        $after = $this->changeTo($this->freeOffer);

        self::assertSame('free', $after->offer->code);
        self::assertSame([], $this->charge->charged);
    }

    // --- The deferred downgrade (spec §4) ------------------------------------

    /**
     * The defect: a downgrade applied at once and took back what the
     * customer had paid for. Somebody on Pro until the 31st who chose the
     * cheaper plan on the 3rd lost Pro on the 3rd.
     *
     * So the choice writes an intention and **touches nothing**: not the
     * offer, not the period, and above all not one entitlement.
     */
    public function testADowngradeIsScheduledAndTakesNothingAway(): void
    {
        $before = $this->subscribeToPro();

        $after = $this->changeTo($this->freeOffer);

        // Still on Pro, still with Pro's grants, still until the same date.
        self::assertSame('pro', $after->offer->code);
        self::assertSame(50, $this->limitFor('max_projects'));
        self::assertContains('advanced_3d', $this->entitlements()->capabilitiesFor($this->tenant, $this->product));
        self::assertSame(
            $before->currentPeriodEnd?->format(DATE_ATOM),
            $after->currentPeriodEnd?->format(DATE_ATOM),
        );

        // And the intention is on the row, dated to the end of what was paid
        // for — never earlier, which is the whole rule.
        self::assertNotNull($after->pending);
        self::assertSame('free', $after->pending->offerCode);
        self::assertSame(
            $before->currentPeriodEnd?->format(DATE_ATOM),
            $after->pending->effectiveAt->format(DATE_ATOM),
        );
        self::assertSame($this->user, $after->pending->requestedBy);

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::CHANGE_SCHEDULED, $latest->type);
    }

    /**
     * The explicit door, and the refusal that keeps it honest: going up is
     * immediate, so scheduling it would hand somebody a plan they are not
     * paying for yet.
     */
    public function testSchedulingAMoveThatIsNotDownIsRefused(): void
    {
        $this->subscribeToFree();

        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->scheduleChange(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        ));

        self::assertSame(409, $error->statusCode());
        self::assertSame('NOT_A_DOWNGRADE', $error->errorCode());
    }

    /**
     * Renewal applies it, in §4.3's order. The period resets, the terms are
     * re-snapshotted from the arriving version, the grants are exchanged and
     * the intention is gone.
     */
    public function testRenewalAppliesTheChangeThatHasComeDue(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->scheduleChange($this->tenant, $this->product, $this->freeOffer, $this->user);

        $applied = $this->renewAtTheBoundary();

        self::assertSame('free', $applied->offer->code);
        self::assertNull($applied->pending, 'the intention is spent, not kept');
        self::assertSame(3, $this->limitFor('max_projects'));
        self::assertSame(
            ['max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'the boolean capability the pro offer granted is gone, now that the period it was paid for has passed',
        );

        // The new period starts where the old one ended, so applying it late
        // costs the customer nothing.
        self::assertNotNull($applied->currentPeriodEnd);
        self::assertGreaterThan($applied->currentPeriodStart, $applied->currentPeriodEnd);

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::OFFER_CHANGED, $latest->type);
        self::assertSame(Subscriptions::DOWNGRADE, $latest->detail->direction ?? null);
        self::assertSame('AT_RENEWAL', $latest->detail->applied ?? null);
    }

    /**
     * A change dated further out is not dragged forward. The clock decides,
     * exactly as it does for a cancellation deferred to a commitment ten
     * months away.
     */
    public function testRenewalLeavesAChangeThatIsNotDueYetAlone(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->scheduleChange($this->tenant, $this->product, $this->freeOffer, $this->user);

        // The period ends tomorrow; the change is a month out, because
        // somebody moved the boundary after it was asked for.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET current_period_end = now() + interval '1 day',
                       pending_effective_at = now() + interval '1 month'
                 WHERE tenant_id = :tenant
                SQL,
            ['tenant' => $this->tenant],
        );

        $renewed = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertSame('pro', $renewed->offer->code, 'renewed on the same offer');
        self::assertNotNull($renewed->pending, 'and the change is still waiting');
    }

    /**
     * §4.2, and the piece that is not optional: a future change that cannot
     * be undone is a cancellation in disguise.
     */
    public function testAScheduledChangeIsWithdrawnAndRecorded(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->scheduleChange($this->tenant, $this->product, $this->freeOffer, $this->user);

        $kept = $this->subscriptions()->cancelScheduledChange($this->tenant, $this->product, $this->user);

        self::assertNull($kept->pending);
        self::assertSame('pro', $kept->offer->code);

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::CHANGE_CANCELLED, $latest->type);

        // Renewal now reconducts the same offer: there is nothing left to
        // apply.
        $renewed = $this->renewAtTheBoundary();
        self::assertSame('pro', $renewed->offer->code);
    }

    public function testWithdrawingAChangeThatIsNotScheduledIsRefused(): void
    {
        $this->subscribeToPro();

        $error = $this->refusal(
            fn (): Subscription => $this->subscriptions()->cancelScheduledChange(
                $this->tenant,
                $this->product,
                $this->user,
            ),
        );

        self::assertSame(409, $error->statusCode());
        self::assertSame('NO_PENDING_CHANGE', $error->errorCode());
    }

    /**
     * §2.2: a subscription has **one** ending. Cancelling clears a pending
     * change, and a subscription already ending refuses one — so the two
     * indicators are never both set, which is what the database also
     * refuses.
     */
    public function testACancellationAndAPendingChangeDoNotCoexist(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->scheduleChange($this->tenant, $this->product, $this->freeOffer, $this->user);

        // Cancelling wins: the subscription is ending, so there is nothing
        // left for it to become.
        $cancelled = $this->subscriptions()->cancel($this->tenant, $this->product, false, $this->user)['subscription'];

        self::assertTrue($cancelled->cancelAtPeriodEnd);
        self::assertNull($cancelled->pending);

        // And the other way round is refused rather than silently deciding
        // which of the two the customer meant.
        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->scheduleChange(
            $this->tenant,
            $this->product,
            $this->freeOffer,
            $this->user,
        ));

        self::assertSame(409, $error->statusCode());
        self::assertSame('SUBSCRIPTION_ENDING', $error->errorCode());
    }

    /**
     * The invariant in the database, not only in the service: a row carrying
     * both endings is refused whatever wrote it.
     */
    public function testTheDatabaseRefusesBothEndingsOnOneRow(): void
    {
        $subscription = $this->subscribeToPro();

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET cancel_at_period_end = true, cancel_effective_at = now() + interval '1 month'
                 WHERE id = :id
                SQL,
            ['id' => $subscription->id],
        );

        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET pending_offer_version_id = offer_version_id,
                       pending_effective_at = now() + interval '1 month',
                       pending_requested_at = now()
                 WHERE id = :id
                SQL,
            ['id' => $subscription->id],
        );
    }

    // --- The terms follow the offer (spec §1c, §3.2) -------------------------

    /**
     * The defect this fixes: `changeOffer` wrote `offer_version_id` and
     * nothing else, so the subscription pointed at the new version while
     * still carrying the conditions of the one it had left. The old
     * cancellation policy decided how to leave the new plan, the old notice
     * applied, and the old renewal rule decided what happened at the term.
     */
    public function testAChangeOfOfferRe_SnapshotsTheTermsOfTheNewVersion(): void
    {
        $before = $this->subscribeToFree();

        // What `free` sells, so the assertions below cannot be reading it.
        self::assertSame('ANYTIME', $before->terms->cancellationPolicy);
        self::assertSame('AUTO_RENEW', $before->terms->renewal);
        self::assertSame(0, $before->terms->noticeDays);
        self::assertNull($before->termEndsAt);

        $after = $this->changeTo($this->termsOffer);

        self::assertSame(24, $after->terms->termMonths);
        self::assertSame('AT_COMMITMENT_END', $after->terms->cancellationPolicy);
        self::assertSame('ENDS_AT_TERM', $after->terms->renewal);
        self::assertSame('CHARGE_REMAINING', $after->terms->earlyTermination);
        self::assertSame(30, $after->terms->noticeDays);

        // The dates the clock will be asked about, not just the numbers: a
        // term of 24 months with no date is a term nothing ever reaches.
        self::assertNotNull($after->termEndsAt);
        self::assertEqualsWithDelta(
            (new DateTimeImmutable('+24 months'))->getTimestamp(),
            $after->termEndsAt->getTimestamp(),
            120,
        );

        // Nothing was committed before, so the arriving commitment ends
        // later than none at all and therefore applies.
        self::assertSame(12, $after->terms->commitmentMonths);
        self::assertNotNull($after->commitmentEndsAt);
    }

    /**
     * §3.3, and the whole subtlety: **a change of plan is not a new
     * contract.** Somebody committed for two years who moves up a tier stays
     * committed until their original date — the move neither re-arms the
     * commitment for another two years nor shortens it to the twelve months
     * the new offer happens to sell.
     */
    public function testACommittedSubscriptionKeepsItsOwnCommitmentAcrossAChange(): void
    {
        $committed = $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->longCommitmentOffer,
            $this->user,
        );

        self::assertSame(24, $committed->terms->commitmentMonths);
        $agreed = $committed->commitmentEndsAt;
        self::assertNotNull($agreed);

        $after = $this->changeTo($this->termsOffer);

        // The date the customer agreed to, to the microsecond. Not the
        // arriving offer's twelve months, which would end sooner, and not a
        // fresh twenty-four, which would end later.
        self::assertSame(24, $after->terms->commitmentMonths);
        self::assertSame($agreed->format(DATE_ATOM), $after->commitmentEndsAt?->format(DATE_ATOM));

        // And everything that is not the commitment did move.
        self::assertSame('AT_COMMITMENT_END', $after->terms->cancellationPolicy);
        self::assertSame(30, $after->terms->noticeDays);
    }

    /**
     * The contradiction the two rules can produce, refused in words rather
     * than by a CHECK constraint: a subscription sold to run six months
     * cannot carry the two-year commitment that survives into it.
     */
    public function testAnOfferShorterThanTheSurvivingCommitmentIsRefused(): void
    {
        $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->longCommitmentOffer,
            $this->user,
        );

        $error = $this->refusal(fn (): Subscription => $this->changeTo($this->shortTermOffer));

        self::assertSame(409, $error->statusCode());
        self::assertSame('COMMITMENT_OUTLASTS_TERM', $error->errorCode());

        // And nothing moved: the subscription is still the one it was.
        $unchanged = $this->subscriptions()->current($this->tenant, $this->product);
        self::assertSame('pro-committed', $unchanged?->offer->code);
    }

    /**
     * A negotiated exception is not a subscription grant, and must survive a
     * plan change — otherwise support's deliberate act quietly disappears
     * the next time the customer upgrades.
     */
    public function testAnOverrideSurvivesAChangeOfOffer(): void
    {
        $this->subscribeToFree();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO entitlements (tenant_id, product_id, feature_id, limit_value, source)
                VALUES (:tenant, :product, :feature, 500, 'OVERRIDE')
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'feature' => $this->projectsFeature],
        );

        $this->changeTo($this->proOffer);

        // The override outranks both grants: most generous wins.
        self::assertSame(500, $this->limitFor('max_projects'));
        self::assertSame('OVERRIDE', $this->sourceFor('max_projects'));
    }

    // --- Cancellation -------------------------------------------------------

    public function testASimpleCancellationKeepsTheMonthAlreadyPaidFor(): void
    {
        $this->subscribeToPro();

        $cancelled = $this->subscriptions()->cancel($this->tenant, $this->product, false, $this->user)['subscription'];

        self::assertSame(Subscription::ACTIVE, $cancelled->status);
        self::assertTrue($cancelled->cancelAtPeriodEnd);
        self::assertNotSame(
            [],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'still entitled until the period ends',
        );

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::CANCELLATION_SCHEDULED, $latest->type);
    }

    public function testAnImmediateCancellationEndsTheEntitlementsWithIt(): void
    {
        $this->subscribeToPro();

        $cancelled = $this->subscriptions()->cancel($this->tenant, $this->product, true, $this->user)['subscription'];

        self::assertSame(Subscription::CANCELLED, $cancelled->status);
        self::assertNotNull($cancelled->endedAt);
        self::assertSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));
        self::assertNull($this->subscriptions()->current($this->tenant, $this->product));
    }

    public function testResumingWithdrawsAScheduledCancellation(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->cancel($this->tenant, $this->product, false, $this->user);

        $resumed = $this->subscriptions()->resume($this->tenant, $this->product, $this->user);

        self::assertFalse($resumed->cancelAtPeriodEnd);
        self::assertNull($resumed->cancelledAt);
    }

    public function testResumingASubscriptionThatIsNotEndingIsRefused(): void
    {
        $this->subscribeToPro();

        $error = $this->refusal(
            fn (): Subscription => $this->subscriptions()->resume($this->tenant, $this->product, $this->user),
        );

        self::assertSame(409, $error->statusCode());
        self::assertSame('NOT_CANCELLING', $error->errorCode());
    }

    /**
     * After an immediate cancellation the tenant may subscribe again — the
     * partial unique index only forbids two *active* at once.
     */
    public function testATenantMaySubscribeAgainAfterCancelling(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->cancel($this->tenant, $this->product, true, $this->user);

        $again = $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freeOffer, $this->user);

        self::assertSame(Subscription::ACTIVE, $again->status);
        self::assertCount(2, $this->subscriptions()->history($this->tenant, $this->product));
    }

    // --- The exit criterion --------------------------------------------------

    /**
     * "Expiring an offer leaves historical subscriptions intact and
     * readable." Structural rather than conventional: the foreign key is
     * RESTRICT, so the version cannot even be deleted.
     */
    public function testExpiringAnOfferLeavesTheSubscriptionReadable(): void
    {
        $subscription = $this->subscribeToPro();
        $versionId = $subscription->offer->version->id;

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :id",
            ['id' => $versionId],
        );

        // Gone from the catalogue — and only it: withdrawing one offer must
        // not take the rest of the price list with it.
        $onSale = array_map(
            static fn (Offer $offer): string => $offer->code,
            (new Catalogue($this->catalogue()))->offersOnSale($this->product),
        );
        self::assertNotContains('pro', $onSale);
        self::assertContains('free', $onSale);

        // …and still completely legible to the tenant who bought it.
        $reloaded = $this->subscriptions()->current($this->tenant, $this->product);
        self::assertNotNull($reloaded);
        self::assertSame('pro', $reloaded->offer->code);
        self::assertSame(2900, $reloaded->offer->version->priceMinorUnits);
        self::assertSame('EUR', $reloaded->offer->version->currency);
        self::assertSame(OfferVersion::EXPIRED, $reloaded->offer->version->status);
        self::assertCount(2, $reloaded->offer->version->grants);

        // And the tenant is still entitled: withdrawing an offer from sale
        // does not cancel the people already on it.
        self::assertNotSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));
    }

    public function testASubscribedOfferVersionCannotBeDeleted(): void
    {
        $subscription = $this->subscribeToPro();

        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            'DELETE FROM offer_versions WHERE id = :id',
            ['id' => $subscription->offer->version->id],
        );
    }

    // --- Helpers -------------------------------------------------------------

    private function subscribeToPro(): Subscription
    {
        return $this->subscriptions()->subscribe($this->tenant, $this->product, $this->proOffer, $this->user);
    }

    private function subscribeToFree(): Subscription
    {
        return $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freeOffer, $this->user);
    }

    /**
     * Renewal, asked the way the M7 job will ask it.
     *
     * Nothing to move: renewal reads the period's own end as the moment it
     * is renewing *to*, and a pending change dated to that same end is
     * therefore due. That is also why applying it an hour late costs the
     * customer nothing.
     */
    private function renewAtTheBoundary(): Subscription
    {
        return $this->subscriptions()->renew($this->tenant, $this->product);
    }

    /**
     * A change of offer, unwrapped.
     *
     * The service answers with the decision and the documents beside the
     * subscription, as cancelling does — because a move up now costs something
     * (spec §3) and a caller that could not see what needs no reading of the
     * database to find out. Most of this file is about what *moved*, so it asks
     * for that and the two tests about the money ask for the rest.
     */
    /**
     * Ten days into a thirty-day period, which is where an upgrade actually
     * happens.
     *
     * Written in SQL because the alternative is waiting, and because a period
     * that starts and is changed in the same second would let a test pass on
     * two clocks agreeing rather than on the anchor having moved.
     */
    private function putThePeriodMidFlight(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET current_period_start = now() - interval '10 days',
                       current_period_end = now() + interval '20 days'
                 WHERE tenant_id = :tenant AND product_id = :product
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product],
        );
    }

    private function changeTo(string $offerId): Subscription
    {
        return $this->subscriptions()->changeOffer($this->tenant, $this->product, $offerId, $this->user)['subscription'];
    }

    private function subscriptions(): Subscriptions
    {
        return new Subscriptions(
            new PostgresSubscriptionRepository($this->connection),
            new Catalogue($this->catalogue()),
            new CancellationPolicy(),
            // Every offer in this file is open-ended, so nothing here may
            // ever cost anything to leave. A double that fails on contact
            // says so: if a cancellation in these scenarios ever reaches for
            // the billing chain, that is the bug, not a detail to stub over.
            new class () implements EarlyTerminationCharge {
                public function applyCharge(
                    Subscription $subscription,
                    CancellationDecision $decision,
                    ?string $actorUserId,
                ): string {
                    TestCase::fail('An open-ended subscription charged for leaving.');
                }
            },
            // The real trail against the real database: a cancellation that
            // is not recorded is one nobody can be held to (§30), and a
            // double here would only prove the double writes nothing.
            new PostgresAuditLog($this->connection),
            // The real arithmetic. It is the subject of two tests here and
            // the thing every other one has to not disturb, so a double would
            // be measuring the double.
            new ProrationPolicy(),
            // Nothing in this file is ever paid for — no billing profile, no
            // invoice, no payment — so nothing may be credited, and the double
            // says so rather than inventing money.
            $this->credit,
            $this->charge,
        );
    }

    private function catalogue(): PostgresCatalogueRepository
    {
        return new PostgresCatalogueRepository(
            $this->connection,
            new OfferVersionLoader($this->connection),
        );
    }

    private function entitlements(): PostgresEntitlementRepository
    {
        return new PostgresEntitlementRepository($this->connection);
    }

    /**
     * @param callable(): Subscription $act
     */
    private function refusal(callable $act): HttpException
    {
        try {
            $act();
        } catch (HttpException $error) {
            return $error;
        }

        self::fail('The operation was allowed.');
    }

    private function statusOf(): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM subscriptions WHERE tenant_id = :tenant',
            ['tenant' => $this->tenant],
        );

        return is_string($status) ? $status : '';
    }

    private function limitFor(string $featureCode): ?int
    {
        foreach ($this->entitlements()->entitlementsFor($this->tenant, $this->product) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement->limit;
            }
        }

        return null;
    }

    private function sourceFor(string $featureCode): ?string
    {
        foreach ($this->entitlements()->entitlementsFor($this->tenant, $this->product) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement->source;
            }
        }

        return null;
    }

    private function entitlementEnd(): ?DateTimeImmutable
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT valid_until FROM entitlements
                 WHERE tenant_id = :tenant AND source = 'SUBSCRIPTION'
                 ORDER BY valid_until DESC LIMIT 1
                SQL,
            ['tenant' => $this->tenant],
        );

        return $row === false ? null : Row::nullableTimestamp($row, 'valid_until');
    }

    private function seedProduct(string $code): string
    {
        return $this->id(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
        );
    }

    private function seedTenant(string $slug): string
    {
        return $this->id(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => ucfirst($slug), 'slug' => $slug],
        );
    }

    private function seedUser(string $subject): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject) VALUES (:subject) RETURNING id',
            ['subject' => $subject],
        );
    }

    private function seedPlan(string $code, int $rank): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO plans (product_id, code, name, rank)
                VALUES (:product, :code, :name, :rank) RETURNING id
                SQL,
            ['product' => $this->product, 'code' => $code, 'name' => $code, 'rank' => $rank],
        );
    }

    private function seedFeature(string $code, string $kind, ?string $unit): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO features (code, name, kind, unit)
                VALUES (:code, :code, :kind, :unit) RETURNING id
                SQL,
            ['code' => $code, 'kind' => $kind, 'unit' => $unit],
        );
    }

    private function seedOffer(string $planId, string $code): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name)
                VALUES (:product, :plan, :code, :code) RETURNING id
                SQL,
            ['product' => $this->product, 'plan' => $planId, 'code' => $code],
        );
    }

    /**
     * A version, with the §13.1 terms it sells.
     *
     * The defaults are what every offer sold before §13.1 amounts to —
     * open-ended, uncommitted, cancellable whenever — so the tests that do
     * not care about terms read as they did.
     */
    private function seedVersion(
        string $offerId,
        int $price,
        string $period,
        ?int $termMonths = null,
        int $commitmentMonths = 0,
        string $cancellationPolicy = 'ANYTIME',
        string $renewal = 'AUTO_RENEW',
        string $earlyTermination = 'FORBIDDEN',
        int $noticeDays = 0,
    ): string {
        return $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from,
                     term_months, commitment_months, cancellation_policy, renewal, early_termination, notice_days)
                VALUES (:offer, 1, 'DRAFT', :period, :price, 'EUR', now() - interval '1 day',
                        :termMonths, :commitmentMonths, :policy, :renewal, :earlyTermination, :noticeDays)
                RETURNING id
                SQL,
            [
                'offer' => $offerId,
                'period' => $period,
                'price' => $price,
                'termMonths' => $termMonths,
                'commitmentMonths' => $commitmentMonths,
                'policy' => $cancellationPolicy,
                'renewal' => $renewal,
                'earlyTermination' => $earlyTermination,
                'noticeDays' => $noticeDays,
            ],
        );
    }

    /**
     * Publishes a version once its grants are attached.
     *
     * A version's grants are frozen the moment it leaves DRAFT (ADR-033), so
     * a fixture has to build one the way the product does: draft, grant,
     * publish. Seeding an ACTIVE row and attaching grants afterwards is an
     * order nothing in the platform actually uses.
     */
    private function publish(string $versionId): void
    {
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $versionId],
        );
    }

    private function grant(string $versionId, string $featureId, ?int $limit): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                VALUES (:version, :feature, :limit)
                SQL,
            ['version' => $versionId, 'feature' => $featureId, 'limit' => $limit],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }
}
