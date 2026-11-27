<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\RenewalPolicy;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\UnstartedPeriod;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\Commerce\Service\Subscriptions;
use App\Entitlement\Domain\Coverage;
use App\Entitlement\Domain\EntitlementRepository;
use App\Job\Domain\Job;
use App\Job\Service\ExpireSubscriptions;
use App\Job\Service\RenewSubscriptions;
use App\Job\Service\SendRenewalNotices;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Payment\Service\Payments;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Exceptions\ConflictException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A paid period rolling into the next one, and being billed for it (ADR-068).
 *
 * Against the real database and the real billing chain, because the document is
 * half the point: `renew()` moved the period and the entitlements forward and
 * raised nothing at all, which was invisible only while its one caller was a
 * service method with no endpoint and no job. A double here would prove the
 * double writes nothing.
 *
 * The first case is the baseline (ADR-071, the operator's decision of
 * 2026-10-29): a product that has said nothing renews, and only a strict
 * `"automatic": false` stops it. The last section is the term rolling, and the
 * notice it waits for.
 */
#[CoversNothing]
final class RenewalPassTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $monthlyOffer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id",
        );
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@acme.test') RETURNING id",
        );

        $this->seedBillingIdentity();
        $this->monthlyOffer = $this->seedMonthlyOffer();

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['subscription.read', 'subscription.manage'],
                ),
            ]),
        ]);
    }

    // --- the baseline, which is renewing (ADR-071) ---------------------------

    public function testAProductThatHasSaidNothingRenews(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $outcome = $this->pass();

        // What an upgrading deployment gets: the subscription continues, and
        // the period it continues into is invoiced.
        self::assertSame(1, $outcome['renewed']);
        self::assertSame(0, $outcome['switched_off']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testSwitchedOffRenewsNothing(): void
    {
        $this->chooseRenewal('{"automatic": false}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(1, $outcome['switched_off']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testAnUnreadableFlagIsNoDecisionAndLeavesTheBaseline(): void
    {
        // A string "false" is what a hand-edited document produces. Only the
        // operator's strict false is a decision to stop.
        $this->chooseRenewal('{"automatic": "false"}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        self::assertSame(1, $this->pass()['renewed']);
    }

    public function testALeadOutsideTheBoundsFallsBackToTheDefaultLead(): void
    {
        $this->chooseRenewal(sprintf(
            '{"automatic": true, "lead_days": %d}',
            RenewalPolicy::LONGEST_LEAD + 1,
        ));
        $this->subscribe();
        $this->periodEndsIn('9 days');

        // Nine days out is inside the bad lead and outside the default seven.
        self::assertSame(1, $this->pass()['not_yet']);
        self::assertSame(0, $this->invoiceCount());
    }

    // --- switched on ----------------------------------------------------------

    public function testItRollsThePeriodAndRaisesARealInvoiceForIt(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $before = $this->subscribe();
        $this->periodEndsIn('1 day');

        $endedAt = $this->currentPeriodEnd();

        self::assertSame(1, $this->pass()['renewed']);

        // The period moved on from where it ended, not from now: renewing a day
        // early must not shorten what the customer paid for.
        $after = $this->currentPeriodEnd();
        self::assertGreaterThan($endedAt, $after);
        self::assertSame(
            $endedAt->format('Y-m-d'),
            $this->currentPeriodStart()->format('Y-m-d'),
        );

        // A real document, numbered, for the period that is starting. Not a
        // placeholder and not a €0 line: this is what the customer is being
        // asked to pay.
        $invoice = $this->onlyInvoice();

        self::assertNotNull($invoice['number']);
        self::assertSame($endedAt->format('Y-m-d'), $invoice['period_start']);
        self::assertSame($after->format('Y-m-d'), $invoice['period_end']);
        self::assertSame($before->id, $invoice['subscription_id']);

        // The offer's own price, and taxed: a domestic sale on €29.00. Asserted
        // rather than assumed, because a renewal priced from the offer *as it
        // stands today* rather than from the version the customer holds would
        // look identical until somebody repriced the catalogue (§13.1).
        $net = self::minorUnits($invoice, 'net_minor_units');
        $vat = self::minorUnits($invoice, 'vat_minor_units');

        self::assertSame(2900, $net);
        self::assertGreaterThan(0, $vat);
        self::assertSame($net + $vat, self::minorUnits($invoice, 'gross_minor_units'));
    }

    /**
     * The operator's answer to R11 (2026-10-05): the next period is invoiced
     * ahead of time, and the customer is mailed a request to pay it, with a
     * link to the invoice they pay from.
     */
    public function testTheCustomerIsAskedToPayAndTheInvoiceIsDueWhenThePeriodStarts(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');

        $endedAt = $this->currentPeriodEnd();

        self::assertSame(1, $this->pass()['renewed']);

        // Due on the day the period it pays for starts. "Payable on receipt"
        // would have had the collection schedule chase it the next morning and
        // suspend a subscription inside a period already paid for.
        $due = $this->connection->fetchOne("SELECT to_char(due_at, 'YYYY-MM-DD') FROM invoices LIMIT 1");
        self::assertSame($endedAt->format('Y-m-d'), $due);

        $request = $this->connection->fetchAssociative(
            'SELECT recipient_user_id, category, legal_effect, payload FROM notifications WHERE type = :type',
            ['type' => 'subscription.renewal_payment_request'],
        );

        self::assertIsArray($request);
        self::assertSame($this->user, $request['recipient_user_id']);
        self::assertSame('BILLING', $request['category']);
        self::assertTrue((bool) $request['legal_effect']);

        self::assertIsString($request['payload']);
        $payload = json_decode($request['payload'], true);
        self::assertIsArray($payload);

        $invoiceId = $this->connection->fetchOne('SELECT id FROM invoices LIMIT 1');
        self::assertIsString($invoiceId);

        // The invoice's own screen, under the organisation's root and for its
        // product — the page the customer pays from.
        self::assertIsString($payload['link'] ?? null);
        self::assertStringEndsWith('/acme/invoices/' . $invoiceId . '?product=atlas', $payload['link']);
        self::assertSame($endedAt->format('Y-m-d'), $payload['due_on'] ?? null);
    }

    public function testNothingIsChasedBeforeTheDayItIsDue(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');
        $this->pass();

        // A day later the invoice is still not due, so the collection read does
        // not see it — the subscription is not suspended inside its paid period.
        TestDatabase::forge($this->connection, ['invoices'], "UPDATE invoices SET issued_at = issued_at - INTERVAL '1 day'");

        $repository = $this->container()->get(PostgresSubscriptionRepository::class);
        self::assertInstanceOf(PostgresSubscriptionRepository::class, $repository);

        self::assertSame([], $repository->overdue(50));
    }

    public function testASecondPassBillsNothingMore(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $this->pass();
        $again = $this->pass();

        // The period is a month out now, so it is not even read. The guard on
        // the update exists for passes that overlap rather than follow, and this
        // is the ordinary case it must not disturb.
        self::assertSame(0, $again['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testItDoesNotRenewBeforeItsLead(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 2}');
        $this->subscribe();
        $this->periodEndsIn('9 days');

        $outcome = $this->pass();

        // Read, because the query bounds by the longest lead any product could
        // choose, and declined, because this product chose two days.
        self::assertSame(0, $outcome['renewed']);
        self::assertSame(1, $outcome['not_yet']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testTwoPassesHoldingTheSamePeriodBillItOnce(): void
    {
        // The guard the ordinary cases cannot reach. Two overlapping passes both
        // read a subscription, the first renews it, and the second is still
        // holding the period that has gone. Without the condition on the update
        // the second would roll the period a second time and bill a second
        // invoice — one period, two documents, and a customer charged twice.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        // The subscription as the losing pass still holds it: read *before* the
        // winning pass runs, so its period end is the one that is about to move.
        // Read through the service, which is how any caller gets one.
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        $held = $subscriptions->current($this->tenant, $this->product, $this->user);
        self::assertNotNull($held);

        $repository = new PostgresSubscriptionRepository($this->connection);

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());

        $billed = 0;

        try {
            $repository->renew(
                $held,
                new DateTimeImmutable('+2 months'),
                null,
                function () use (&$billed): string {
                    $billed++;

                    return 'never';
                },
            );

            self::fail('A second renewal of the same period was allowed.');
        } catch (ConflictException $refusal) {
            self::assertSame('RENEWAL_ALREADY_APPLIED', $refusal->errorCode());
        }

        // Nothing was billed, and the period did not move again: the update
        // refuses before the closure runs, and the transaction takes the rest
        // with it.
        self::assertSame(0, $billed);
        self::assertSame(1, $this->invoiceCount());
    }

    // --- what it must not touch ----------------------------------------------

    public function testALapsedSubscriptionIsNotEvenRead(): void
    {
        // The point of renewing *before* the period ends. A lapsed subscription
        // is `sweep.subscriptions`'s, and a pass that took it would be racing
        // that sweep over whether somebody keeps their subscription.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('-1 day');

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(0, $outcome['switched_off']);
        self::assertSame(0, $outcome['not_yet']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testASubscriptionCancellingAtPeriodEndIsNotRenewed(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = current_period_end',
        );

        // Renewing here would extend a subscription past the date the customer
        // was given and bill them for a period they cancelled.
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testASubscriptionInArrearsIsNotRenewed(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();

        // One period billed, so there is a real debt for the arrears to name:
        // `subscriptions_arrears_name_their_debt` requires the date and the
        // invoice together, because arrears with no document are arrears nobody
        // can be asked to settle.
        $this->periodEndsIn('1 day');
        self::assertSame(1, $this->pass()['renewed']);

        $this->periodEndsIn('1 day');
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET status = 'PAST_DUE',
                       past_due_since = now(),
                       past_due_invoice_id = (SELECT id FROM invoices LIMIT 1)
                SQL,
        );

        // It owes for the period it already had and the dunning pass is chasing
        // it. A second invoice against a suspended service compounds a debt
        // somebody is already being asked for.
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    // --- arrears under commitment (ADR-072) ----------------------------------

    public function testArrearsUnderACommitmentKeepRenewingAndStaySuspended(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $debt = $this->inArrearsWithAPeriodEndingIn('1 day');
        $this->committedFor('6 months');

        $endedAt = $this->currentPeriodEnd();

        // One missed payment does not end a twenty-four-month contract: the
        // period rolls and the next one is invoiced, like any renewal.
        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(2, $this->invoiceCount());
        $after = $this->currentPeriodEnd();
        self::assertGreaterThan($endedAt, $after);
        self::assertSame($endedAt->format('Y-m-d'), $this->currentPeriodStart()->format('Y-m-d'));

        // And it is not reactivated by renewing: still suspended, still naming
        // the debt that suspended it — only paying lifts that.
        $row = $this->connection->fetchAssociative('SELECT status, past_due_invoice_id FROM subscriptions');
        self::assertIsArray($row);
        self::assertSame('PAST_DUE', $row['status']);
        self::assertSame($debt, $row['past_due_invoice_id']);

        // The entitlements moved with the period, and the workshop stays shut:
        // the person is told they are in arrears, not that they have nothing.
        $entitlements = $this->container()->get(EntitlementRepository::class);
        self::assertInstanceOf(EntitlementRepository::class, $entitlements);
        self::assertSame(Coverage::IN_ARREARS, $entitlements->coverageFor($this->tenant, $this->product, $this->user));

        // The new period's invoice is due the day it starts, like any renewal.
        $due = $this->connection->fetchOne(
            "SELECT to_char(due_at, 'YYYY-MM-DD') FROM invoices WHERE id <> :debt",
            ['debt' => $debt],
        );
        self::assertSame($endedAt->format('Y-m-d'), $due);
    }

    public function testArrearsWhoseCommitmentEndsWithThisPeriodAreNotRenewed(): void
    {
        // The commitment is over at the boundary being crossed: nothing binds
        // the customer to the next period, so arrears are the dunning pass's.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->inArrearsWithAPeriodEndingIn('1 day');
        $this->committedFor('0 days');

        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testTheSweepLeavesArrearsUnderCommitmentToTheRenewal(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->inArrearsWithAPeriodEndingIn('-1 day');
        $this->committedFor('6 months');

        // Cron missed the window. The sweep must not end a contract the
        // customer is bound to because one invoice went unpaid…
        self::assertSame(0, $this->sweep());
        $afterTheSweep = $this->connection->fetchOne('SELECT status FROM subscriptions');
        self::assertSame('PAST_DUE', $afterTheSweep);

        // …and the renewal catches the period up, from where it ended.
        $endedAt = $this->currentPeriodEnd();

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(2, $this->invoiceCount());
        self::assertSame($endedAt->format('Y-m-d'), $this->currentPeriodStart()->format('Y-m-d'));
        $afterTheRenewal = $this->connection->fetchOne('SELECT status FROM subscriptions');
        self::assertSame('PAST_DUE', $afterTheRenewal);
    }

    /**
     * **A lawful cancellation is not undone by a debt** (ADR-078).
     *
     * In arrears, under a commitment, and cancelled by a policy that allows it:
     * the renewal refuses to roll past the date the customer was given, and the
     * sweep refused the row for being committed arrears. Neither job took it,
     * so it stayed suspended for ever — chased, and holding the one-seat index
     * that stops its holder from subscribing again.
     */
    public function testCommittedArrearsTheCustomerCancelledEndWhenTheirDateComes(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $subscription = $this->subscribe();
        $debt = $this->inArrearsWithAPeriodEndingIn('-1 day');
        $this->committedFor('6 months');
        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = current_period_end',
        );

        // The renewal still does not roll it…
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());

        // …and the sweep now ends it, saying which kind of ending this was.
        self::assertSame(1, $this->sweep());
        self::assertSame('EXPIRED', $this->connection->fetchOne('SELECT status FROM subscriptions'));
        self::assertSame(
            'CANCELLATION_DUE_IN_ARREARS',
            $this->connection->fetchOne(
                "SELECT detail->>'reason' FROM subscription_events WHERE subscription_id = :id AND type = 'EXPIRED'",
                ['id' => $subscription->id],
            ),
        );

        // What was owed is still owed: ending a contract settles nothing.
        self::assertSame('ISSUED', $this->connection->fetchOne('SELECT status FROM invoices WHERE id = :id', ['id' => $debt]));

        // And the person can subscribe again, which the index refused while
        // the dead row was still live.
        self::assertEquals(0, $this->connection->fetchOne(
            "SELECT count(*) FROM subscriptions WHERE status IN ('ACTIVE', 'PAST_DUE')",
        ));
    }

    /**
     * A cancellation dated later is not due yet, so the commitment still holds
     * the row for the renewal — the two jobs never want the same one.
     */
    public function testCommittedArrearsCancelledForLaterAreStillTheRenewals(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->inArrearsWithAPeriodEndingIn('-1 day');
        $this->committedFor('6 months');
        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = commitment_ends_at',
        );

        self::assertSame(0, $this->sweep());
        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame('PAST_DUE', $this->connection->fetchOne('SELECT status FROM subscriptions'));
    }

    /**
     * Arrears at a term that does not roll end there, and the history says so
     * once (2026-11-27). `expire()` matched ACTIVE alone, so the row stayed
     * suspended while an EXPIRED event was written for it on every pass.
     */
    public function testArrearsReachingATermThatDoesNotRollEndOnceAndForReal(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $subscription = $this->subscribe();
        $this->inArrearsWithAPeriodEndingIn('1 day');
        $this->committedFor('6 months');
        $this->connection->executeStatement(
            "UPDATE subscriptions SET renewal = 'ENDS_AT_TERM', term_months = NULL, term_ends_at = NULL",
        );

        $service = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $service);

        $ended = $service->renew($subscription->tenantId, $subscription->productId, $subscription->subscriberUserId);

        self::assertSame(Subscription::EXPIRED, $ended->status);
        self::assertEquals(1, $this->connection->fetchOne(
            "SELECT count(*) FROM subscription_events WHERE type = 'EXPIRED'",
        ));
    }

    // --- cancelling before a period invoiced ahead begins (ADR-079) -----------

    /**
     * The renewal rolls and invoices a lead early, so for those days the row
     * describes a period that has not begun. Cancelling "at period end" then
     * meant the end of *that* one: a whole period more, with nothing saying so.
     *
     * The operator's decision (2026-11-27): it ends with the period actually
     * running, and the invoice for the one that never began is withdrawn.
     */
    public function testCancellingBeforeAPeriodInvoicedAheadBeginsEndsWithTheOneRunning(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');
        $runningEnds = $this->currentPeriodEnd();

        self::assertSame(1, $this->pass()['renewed']);
        self::assertGreaterThan(new DateTimeImmutable(), $this->currentPeriodStart(), 'the row is rolled ahead');

        $outcome = $this->cancelAtPeriodEnd();

        // The date the customer is given is the end of what they paid for.
        self::assertSame('cancel.at_period_end', $outcome['decision']->ruleId);
        self::assertSame($runningEnds->format('Y-m-d'), $outcome['decision']->effectiveAt?->format('Y-m-d'));

        // The invoice of the period that will not happen is cancelled, and the
        // VAT it declared is reversed with it.
        self::assertSame('INVOICE_CANCELLED', $outcome['unstarted_period']?->outcome);
        self::assertSame('CANCELLED', $this->connection->fetchOne('SELECT status FROM invoices'));
        self::assertEquals(0, $this->connection->fetchOne('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM credit_notes'));

        // The row is back on the period running, and the entitlements end with it.
        self::assertSame($runningEnds->format('Y-m-d'), $this->currentPeriodEnd()->format('Y-m-d'));
        self::assertLessThan(new DateTimeImmutable(), $this->currentPeriodStart());
        self::assertSame(
            $runningEnds->format('Y-m-d'),
            $this->connection->fetchOne("SELECT to_char(max(valid_until), 'YYYY-MM-DD') FROM entitlements"),
        );

        // And nothing rolls it again: the cancellation is due at this period's end.
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());

        // The history says what was taken back and how.
        self::assertSame(
            'INVOICE_CANCELLED',
            $this->connection->fetchOne(
                "SELECT detail #>> '{unstarted_period,outcome}' FROM subscription_events WHERE type = 'CANCELLATION_SCHEDULED'",
            ),
        );
    }

    /**
     * Already paid for, it is given back: the card is credited through the
     * ordinary refund chain, which raises the credit note that makes the
     * fiscal fact follow the money (ADR-058).
     */
    public function testAPeriodPaidAheadAndCancelledBeforeItBeginsIsRefundedWithItsCreditNote(): void
    {
        $this->override([
            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider('renewal-secret')]),
        ]);
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');
        $runningEnds = $this->currentPeriodEnd();
        self::assertSame(1, $this->pass()['renewed']);

        $invoiceId = $this->id('SELECT id FROM invoices LIMIT 1');
        $this->payByCard($invoiceId);
        self::assertSame('PAID', $this->connection->fetchOne('SELECT status FROM invoices'));

        $outcome = $this->cancelAtPeriodEnd();

        self::assertSame('INVOICE_REFUNDED', $outcome['unstarted_period']?->outcome);
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM refunds'));
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM credit_notes'));

        // Everything it collected, and not a cent of the period that ran.
        self::assertEquals(
            $this->connection->fetchOne('SELECT gross_minor_units FROM invoices'),
            $this->connection->fetchOne('SELECT amount_minor_units FROM refunds'),
        );
        self::assertSame($runningEnds->format('Y-m-d'), $this->currentPeriodEnd()->format('Y-m-d'));
    }

    /**
     * A cancellation deferred past the period invoiced ahead leaves it alone:
     * that period will run, and is owed.
     */
    public function testACancellationDeferredToACommitmentLeavesThePeriodInvoicedAheadOwed(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');
        self::assertSame(1, $this->pass()['renewed']);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET commitment_months = 12,
                       commitment_ends_at = current_period_end + INTERVAL '6 months',
                       cancellation_policy = 'AT_COMMITMENT_END'
                SQL,
        );

        $outcome = $this->cancelAtPeriodEnd();

        self::assertSame('cancel.deferred_to_commitment_end', $outcome['decision']->ruleId);
        self::assertNull($outcome['unstarted_period']);
        self::assertSame('ISSUED', $this->connection->fetchOne('SELECT status FROM invoices'));
        self::assertGreaterThan(new DateTimeImmutable(), $this->currentPeriodStart());
    }

    /**
     * **The screen is told, it does not work it out** (ADR-079). After the
     * early roll the row's own period is one that has not begun; the read says
     * until when the service is actually paid, what was invoiced ahead, what
     * follows, and what cancelling now would do.
     */
    public function testTheReadSaysWhatHappensNextRatherThanLeavingItToTheScreen(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('6 days');
        $runningEnds = $this->currentPeriodEnd();

        $before = $this->outlook();
        self::assertSame('RENEWS_PERIOD', $before['continuation'] ?? null);
        self::assertNull($before['next_period'] ?? null);

        self::assertSame(1, $this->pass()['renewed']);
        $invoiceId = $this->id('SELECT id FROM invoices LIMIT 1');

        $after = $this->outlook();

        self::assertIsString($after['service_until'] ?? null);
        self::assertSame($runningEnds->format('Y-m-d'), (new DateTimeImmutable($after['service_until']))->format('Y-m-d'));

        $next = $after['next_period'] ?? null;
        self::assertIsArray($next);
        self::assertSame($invoiceId, $next['invoice_id'] ?? null);

        // Cancelling now ends with the period running, not the one invoiced ahead.
        $ifCancelled = $after['if_cancelled_now'] ?? null;
        self::assertIsArray($ifCancelled);
        self::assertTrue($ifCancelled['accepted'] ?? null);
        self::assertIsString($ifCancelled['effective_at'] ?? null);
        self::assertSame($runningEnds->format('Y-m-d'), (new DateTimeImmutable($ifCancelled['effective_at']))->format('Y-m-d'));

        // Once cancelled, it says it ends, and when.
        $this->cancelAtPeriodEnd();
        $ended = $this->outlook();
        self::assertSame('ENDS', $ended['continuation'] ?? null);
        self::assertIsString($ended['ends_on'] ?? null);
        self::assertSame($runningEnds->format('Y-m-d'), (new DateTimeImmutable($ended['ends_on']))->format('Y-m-d'));
        self::assertNull($ended['next_period'] ?? null);
    }

    /** A term about to renew names the day it does and the last day to refuse. */
    public function testTheReadNamesTheDayATermRenewsAndTheLastDayToRefuse(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 7}');
        $this->subscribe();
        $this->periodEndsIn('20 days');
        $this->lastPeriodOfATerm(0, 30);

        $outlook = $this->outlook();

        self::assertSame('RENEWS_TERM', $outlook['continuation'] ?? null);
        self::assertIsString($outlook['term_renews_on'] ?? null);
        self::assertSame(
            (new DateTimeImmutable($outlook['term_renews_on']))->modify('-7 days')->format('Y-m-d'),
            $outlook['term_cancel_by'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function outlook(): array
    {
        $response = $this->request(
            'GET',
            '/api/v1/subscription',
            ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'],
        );
        self::assertSame(200, $response->getStatusCode());

        $outlook = $this->decode($response)['outlook'] ?? null;
        self::assertIsArray($outlook);

        /** @var array<string, mixed> $outlook */
        return $outlook;
    }

    /**
     * @return array{
     *     subscription: Subscription,
     *     decision: CancellationDecision,
     *     charge_invoice_id: string|null,
     *     unstarted_period: UnstartedPeriod|null,
     * }
     */
    private function cancelAtPeriodEnd(): array
    {
        $service = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $service);

        return $service->cancel($this->tenant, $this->product, false, $this->user);
    }

    /** One card payment of the invoice, settled the way a provider settles it. */
    private function payByCard(string $invoiceId): void
    {
        $payments = $this->container()->get(Payments::class);
        self::assertInstanceOf(Payments::class, $payments);

        $started = $payments->start($this->tenant, $this->product, $invoiceId, $this->user);
        $stub = new StubPaymentProvider('renewal-secret');
        $body = json_encode([
            'id' => 'evt_renewal_paid',
            'type' => 'payment.succeeded',
            'payment_id' => $started['payment']->providerPaymentId,
        ], JSON_THROW_ON_ERROR);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => $stub->sign($body)],
            $body,
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheSweepStillEndsArrearsWithNoCommitment(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->inArrearsWithAPeriodEndingIn('-1 day');

        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->sweep());
        self::assertSame('EXPIRED', $this->connection->fetchOne('SELECT status FROM subscriptions'));
    }

    public function testAPeriodThatWouldRunPastTheTermIsRefusedRatherThanShortened(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        // A term ten days out, with a monthly period: the next period would end
        // three weeks after the contract. Billing a whole month for ten days
        // overcharges; capping the end would sell service past the term. So it
        // is refused, and the subscription reaches its term.
        $this->connection->executeStatement(
            "UPDATE subscriptions SET term_ends_at = current_period_end + INTERVAL '10 days'",
        );

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(['RENEWAL_WOULD_PASS_TERM' => 1], $outcome['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    // --- what the baseline made live (audit, 2026-10-29) ----------------------

    public function testAFreePeriodIsNotEndedEarlyByThePass(): void
    {
        // Sold as one period that ends: the pass ran four days before its end
        // and expired it then. Its end is the sweep's, when it lapses.
        $this->subscribe();
        $this->periodEndsIn('4 days');
        $this->connection->executeStatement(
            "UPDATE subscriptions SET renewal = 'ENDS_AT_TERM', term_months = NULL, term_ends_at = NULL",
        );

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame([], $outcome['refused']);
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT status FROM subscriptions'));
    }

    public function testAContractEndingAtItsTermRollsThePeriodsInsideIt(): void
    {
        // A twelve-month contract sold as ending at its term was expired at
        // its first renewal, a month in.
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET renewal = 'ENDS_AT_TERM', term_months = 12,
                       term_ends_at = current_period_end + INTERVAL '6 months'
                SQL,
        );

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT status FROM subscriptions'));
    }

    public function testACancellationDeferredToItsDateStillRenewsUntilThen(): void
    {
        // Cancelling under commitment defers the end; every deferral set the
        // flag the pass refused, so the customer walked out at the period end
        // they asked in, months before the date they were given.
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->connection->executeStatement(
            "UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = current_period_end + INTERVAL '6 months'",
        );

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testAFreePlanRenewsWithoutADocument(): void
    {
        // Nothing outstanding raises no document: it raised a €0 invoice and
        // a request to pay it, every period, each a permanent number.
        $this->subscribe();
        $this->periodEndsIn('1 day');
        TestDatabase::forge($this->connection, ['offer_versions'], 'UPDATE offer_versions SET price_minor_units = 0');

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(0, $this->invoiceCount());
        $requests = $this->connection->fetchOne(
            "SELECT count(*) FROM notifications WHERE type = 'subscription.renewal_payment_request'",
        );
        self::assertIsNumeric($requests);
        self::assertSame(0, (int) $requests);
    }

    public function testATermEndingJustAfterItsLastPeriodStillRolls(): void
    {
        // The term is counted from one clock and the periods from another,
        // microseconds apart; equality was required, so such a term never
        // rolled after its customer had been told it would.
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);
        $this->connection->executeStatement(
            "UPDATE subscriptions SET term_ends_at = term_ends_at + INTERVAL '1 second',"
            . " commitment_ends_at = commitment_ends_at + INTERVAL '1 second'",
        );
        $this->noticeRaised(daysAgo: 40);

        self::assertSame(1, $this->pass()['renewed']);
    }

    // --- the term rolling (ADR-071) -------------------------------------------

    public function testAnOfferSoldAsEndingAtItsTermIsNotReadAtItsTerm(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);
        $this->connection->executeStatement("UPDATE subscriptions SET renewal = 'ENDS_AT_TERM'");

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(0, $outcome['not_yet']);
        self::assertSame([], $outcome['refused']);
    }

    public function testATermIsNotRolledBeforeTheCustomerWasTold(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(['TERM_RENEWAL_NOT_NOTICED' => 1], $outcome['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testATermIsNotRolledWhileTheNoticeIsYoungerThanItsDays(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);

        // Mailed yesterday: the customer has not had the thirty days the offer
        // promised before the day the notice said to cancel by.
        $this->noticeRaised(daysAgo: 1);

        self::assertSame(['TERM_RENEWAL_NOT_NOTICED' => 1], $this->pass()['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testANoticeRaisedButNeverMailedDoesNotCount(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);

        // Raised long ago and suppressed: it told nobody, so the term does not
        // roll, and at its end the subscription ends — the safe side.
        $this->noticeRaised(daysAgo: 40, outcome: 'SUPPRESSED');

        self::assertSame(['TERM_RENEWAL_NOT_NOTICED' => 1], $this->pass()['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testATermToldInTimeRollsIntoTheNextOneWithItsFirstPeriod(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);
        $this->noticeRaised(daysAgo: 40);

        $endingTerm = $this->moment('term_ends_at');

        self::assertSame(1, $this->pass()['renewed']);

        // Twelve months on from the end of the term that ended, never from now:
        // renewing a day early must neither shorten the next term nor push its
        // commitment past what the offer sold.
        $expected = $endingTerm->modify('+12 months')->format('Y-m-d');

        self::assertSame($expected, $this->moment('term_ends_at')->format('Y-m-d'));
        self::assertSame($expected, $this->moment('commitment_ends_at')->format('Y-m-d'));
        self::assertSame($endingTerm->format('Y-m-d'), $this->currentPeriodStart()->format('Y-m-d'));
        self::assertSame(1, $this->invoiceCount());

        // The history says a term rolled, with the date it runs to.
        $detail = $this->connection->fetchOne(
            "SELECT detail->>'term_renewed' FROM subscription_events WHERE type = 'RENEWED'",
        );
        self::assertSame('true', $detail);
    }

    public function testACommitmentWithNoNoticePromisedNeverRollsByItself(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 0);

        self::assertSame(['TERM_RENEWAL_NOT_NOTICED' => 1], $this->pass()['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testATermThatCommitsToNothingRollsWithoutANotice(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 0, noticeDays: 0);

        // Nothing binding is re-armed: the customer may leave the next day.
        self::assertSame(1, $this->pass()['renewed']);
        self::assertNull($this->connection->fetchOne('SELECT commitment_ends_at FROM subscriptions'));
    }

    public function testACancellationAskedForInTheNoticeWindowStopsTheRoll(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');
        $this->lastPeriodOfATerm(commitmentMonths: 12, noticeDays: 30);
        $this->noticeRaised(daysAgo: 40);

        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = term_ends_at',
        );

        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(0, $this->invoiceCount());
    }

    // --- helpers -------------------------------------------------------------

    /**
     * One period renewed and billed, then the period moved to end `$interval`
     * from now and the subscription declared in arrears on that invoice — a
     * real debt, because `subscriptions_arrears_name_their_debt` wants one.
     * Returns the invoice the arrears name.
     */
    private function inArrearsWithAPeriodEndingIn(string $interval): string
    {
        $this->periodEndsIn('1 day');
        self::assertSame(1, $this->pass()['renewed']);

        $debt = $this->id('SELECT id FROM invoices LIMIT 1');

        $this->periodEndsIn($interval);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET status = 'PAST_DUE', past_due_since = now(), past_due_invoice_id = :debt
                SQL,
            ['debt' => $debt],
        );

        return $debt;
    }

    /** A commitment that runs `$interval` past the current period's end. */
    private function committedFor(string $interval): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE subscriptions
                       SET commitment_months = 12,
                           commitment_ends_at = current_period_end + INTERVAL '%s'
                    SQL,
                $interval,
            ),
        );
    }

    private function sweep(): int
    {
        $handler = $this->container()->get(ExpireSubscriptions::class);
        self::assertInstanceOf(ExpireSubscriptions::class, $handler);

        $expired = $handler->handle($this->job())['expired'] ?? null;
        self::assertIsInt($expired);

        return $expired;
    }

    /** @return array<string, mixed> */
    private function pass(): array
    {
        $handler = $this->container()->get(RenewSubscriptions::class);
        self::assertInstanceOf(RenewSubscriptions::class, $handler);

        return $handler->handle($this->job());
    }

    /**
     * The handler takes a Job and reads nothing from it: the work is whatever is
     * due, not whatever the payload says. A bare queued job is a faithful
     * stand-in rather than a shortcut.
     */
    private function job(): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            RenewSubscriptions::TYPE,
            'QUEUED',
            null,
            null,
            [],
            null,
            null,
            0,
            0,
            3,
            $now,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    private function subscribe(): Subscription
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        return $subscriptions->subscribe(
            $this->tenant,
            $this->product,
            $this->monthlyOffer,
            $this->user,
            $this->user,
        );
    }

    private function chooseRenewal(string $document): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'renewal', CAST(:value AS jsonb))
                SQL,
            ['product' => $this->product, 'value' => $document],
        );
    }

    /**
     * Moves the paid period so it ends `$interval` from now.
     *
     * The start moves with it, a month earlier, because
     * `subscriptions_period_ordered` is a CHECK and a period that ends before it
     * begins is not a state this platform can hold — which is the right answer
     * and makes the helper say so.
     */
    private function periodEndsIn(string $interval): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE subscriptions
                       SET current_period_end = now() + INTERVAL '%1$s',
                           current_period_start = now() + INTERVAL '%1$s' - INTERVAL '1 month'
                    SQL,
                $interval,
            ),
        );
    }

    /**
     * Makes the current period the last of a twelve-month term.
     *
     * The start moves a year back, so the term is one the subscription has
     * actually run: `subscriptions_term_after_start`.
     */
    private function lastPeriodOfATerm(int $commitmentMonths, int $noticeDays): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET started_at = current_period_end - INTERVAL '12 months',
                       term_months = 12,
                       term_ends_at = current_period_end,
                       commitment_months = :commitment,
                       commitment_ends_at = CASE WHEN :commitment > 0 THEN current_period_end END,
                       notice_days = :notice,
                       renewal = 'AUTO_RENEW'
                SQL,
            ['commitment' => $commitmentMonths, 'notice' => $noticeDays],
        );
    }

    /**
     * The real notice, raised by its own job, then mailed and aged.
     *
     * The mail is marked sent here rather than through the dispatcher, which
     * needs a transport: what is under test is that the renewal reads the
     * delivery, not how the delivery is made.
     */
    private function noticeRaised(int $daysAgo, string $outcome = 'SENT'): void
    {
        $handler = $this->container()->get(SendRenewalNotices::class);
        self::assertInstanceOf(SendRenewalNotices::class, $handler);

        // Raised **when it was due**, which is in this test's past: the term
        // already ends tomorrow, and a notice raised today would be too late
        // to honour — the job says so and tells the customer the term ends
        // instead (ADR-079). So the subscription is moved forward for the
        // length of the job's run, to the day the notice was due — the default
        // renewal lead and the notice before the period's end, plus a day —
        // and moved back with the dates the notice named.
        $shift = $this->connection->fetchOne(
            'SELECT (current_date + 8 + notice_days) - CAST(current_period_end AS date) FROM subscriptions LIMIT 1',
        );
        self::assertIsInt($shift);
        $this->shiftTheContract($shift);
        self::assertSame(1, $handler->handle($this->job())['raised'] ?? null);
        $this->shiftTheContract(-$shift);
        TestDatabase::forge(
            $this->connection,
            ['notifications'],
            <<<'SQL'
                UPDATE notifications n
                   SET dedup_key = s.id::text || ':' || to_char(s.term_ends_at, 'YYYY-MM-DD'),
                       payload = n.payload || jsonb_build_object(
                           'renews_on', to_char(CAST(n.payload ->> 'renews_on' AS date) - CAST(:days AS integer), 'YYYY-MM-DD'),
                           'cancel_by', to_char(CAST(n.payload ->> 'cancel_by' AS date) - CAST(:days AS integer), 'YYYY-MM-DD')
                       )
                  FROM subscriptions s
                 WHERE n.type = 'subscription.renewal_notice'
                SQL,
            ['days' => $shift],
        );

        $sentAt = $outcome === 'SENT' ? 'now() - make_interval(days => :days)' : 'NULL';
        $reason = $outcome === 'SUPPRESSED' ? "'OPTED_OUT'" : 'NULL';

        TestDatabase::forge(
            $this->connection,
            ['notifications', 'notification_deliveries'],
            "UPDATE notification_deliveries SET status = :status, sent_at = {$sentAt},"
            . " suppression_reason = {$reason} WHERE channel = 'EMAIL'",
            ['status' => $outcome, 'days' => $daysAgo],
        );
    }

    /** Moves the period, the term and the commitment by a number of days. */
    private function shiftTheContract(int $days): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET current_period_start = current_period_start + make_interval(days => :days),
                       current_period_end = current_period_end + make_interval(days => :days),
                       term_ends_at = term_ends_at + make_interval(days => :days),
                       commitment_ends_at = commitment_ends_at + make_interval(days => :days)
                SQL,
            ['days' => $days],
        );
    }

    private function currentPeriodEnd(): DateTimeImmutable
    {
        return $this->moment('current_period_end');
    }

    private function currentPeriodStart(): DateTimeImmutable
    {
        return $this->moment('current_period_start');
    }

    private function moment(string $column): DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT ' . $column . ' FROM subscriptions LIMIT 1');
        self::assertIsString($value);

        return new DateTimeImmutable($value);
    }

    /** @param array<string, mixed> $row */
    private static function minorUnits(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function invoiceCount(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM invoices');

        return is_numeric($count) ? (int) $count : -1;
    }

    /** @return array<string, mixed> */
    private function onlyInvoice(): array
    {
        self::assertSame(1, $this->invoiceCount());

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT number, subscription_id,
                       net_minor_units, vat_minor_units, gross_minor_units,
                       to_char(period_start, 'YYYY-MM-DD') AS period_start,
                       to_char(period_end, 'YYYY-MM-DD') AS period_end
                  FROM invoices LIMIT 1
                SQL,
        );

        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }

    private function seedBillingIdentity(): void
    {
        $supplier = json_encode([
            'legal_name' => 'Atlas SAS',
            'vat_number' => 'FR12345678901',
            'registration_number' => '123 456 789 00012',
            'address_line1' => '1 rue de la Paix',
            'postal_code' => '75002',
            'city' => 'Paris',
            'country_code' => 'FR',
        ]);

        self::assertIsString($supplier);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "FR", "oss_registered": true}' AS jsonb))
                SQL,
            ['product' => $this->product, 'supplier' => $supplier],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, address_line1, postal_code, city, country_code)
                VALUES (:tenant, 'Acme SARL', '12 avenue des Champs', '75008', 'Paris', 'FR')
                SQL,
            ['tenant' => $this->tenant],
        );
    }

    /**
     * Open-ended, monthly, €29. Built the way the product builds one: DRAFT,
     * then its grants, then active — a version's grants are frozen once it
     * leaves DRAFT (ADR-033).
     */
    private function seedMonthlyOffer(): string
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $feature = $this->id(
            'INSERT INTO features (code, name, kind, unit)'
            . " VALUES ('max_projects', 'max_projects', 'QUOTA', 'projects') RETURNING id",
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'pro-monthly', 'Pro monthly') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency,
                     valid_from, term_months, commitment_months, cancellation_policy,
                     renewal, early_termination)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day',
                        NULL, 0, 'ANYTIME', 'AUTO_RENEW', 'FREE')
                RETURNING id
                SQL,
            ['offer' => $offer],
        );

        $this->connection->executeStatement(
            'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)'
            . ' VALUES (:version, :feature, 3)',
            ['version' => $version, 'feature' => $feature],
        );

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        return $offer;
    }
}
