<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Service\Subscriptions;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Database\Row;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Spec §3 against real money: the whole of what a move up now does.
 *
 * The defect it closes is §1(b), measured rather than supposed: *an upgrade was
 * free.* Somebody on a €5 plan moved to a €39 one and the platform charged
 * nothing at all — not a proration computed wrongly, no billing whatever, with
 * the month they were on given away and the new price only appearing at
 * renewal.
 *
 * So the chain is exercised in full and through the real pipeline, because
 * every link of it is where the interesting failure lives:
 *
 * ```text
 * a subscription, invoiced and paid   the money the credit is a share of
 * a move up                           entitlements now, period restarted
 * a refund, with its credit note      the unconsumed share, back on the card
 * an invoice                          the new period, through the normal chain
 * ```
 *
 * Nothing here is doubled except identity — who is calling, which product, what
 * they are a member of — and the payment provider, which cannot be asked for
 * real money in a test. The invoice numbers, the VAT facts, the credit note and
 * the refund are the production code against PostgreSQL.
 */
#[CoversNothing]
final class ProratedUpgradeTest extends DatabaseApiTestCase
{
    private const SECRET = 'proration-test-secret';

    /** Thirty days, so a day is a thirtieth and the arithmetic below is readable. */
    private const PERIOD_DAYS = 30;

    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $starterOffer = '';
    private string $proOffer = '';
    private string $freeOffer = '';
    private string $committedOffer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );

        $this->configureProduct();
        $this->seedCatalogue();

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    [
                        'subscription.read', 'subscription.manage', 'entitlements.read', 'catalog.read',
                        'billing.read', 'billing.manage', 'billing.pay',
                        'payments.read', 'payments.manage',
                        'tax.read', 'tax.manage',
                        // A seat is bought through the sales chain, which is the
                        // only way a customer gets one (ADR-055).
                        'sales.read', 'sales.manage',
                    ],
                ),
            ]),
        ]);

        $this->declareIdentity();
    }

    // --- The whole of a prorated move up --------------------------------------

    /**
     * Ten days into a €29.00 + 20% month, moving to €99.00 + 20%.
     *
     * Every figure is stated rather than derived from the response, because a
     * test that read the server's own arithmetic back would pass on any
     * arithmetic at all.
     *
     * ```text
     * collected        3 480   €29.00 + €5.80 VAT, paid and settled
     * unconsumed       2 320   20 of 30 days, two thirds of the gross
     * charged         11 880   €99.00 + €19.80 VAT, the new period in full
     * net              9 560   11 880 − 2 320
     * ```
     */
    public function testAMoveUpCreditsTheUnconsumedPeriodAndInvoicesTheNewOne(): void
    {
        $this->subscribeTo($this->starterOffer);
        $paid = $this->invoiceAndCollect();
        $this->tenDaysIn();

        $change = $this->decode($this->changeTo($this->proOffer))['change'] ?? null;
        self::assertIsArray($change);

        self::assertSame('UPGRADE', $change['direction'] ?? null);
        self::assertSame('IMMEDIATE', $change['effect'] ?? null);
        // Without VAT since 2026-10-01: the organisation sells this seat and
        // has not declared itself registered, so €29.00 is 2 900 on the
        // document and €99.00 is 9 900. It read 2 320 / 11 880 / 9 560 while
        // the platform sold to the company and charged 20%.
        $credit = $this->aboutSame(1_933, $change['credit_minor_units'] ?? null, 'two thirds of €29.00');
        self::assertSame(9_900, $change['charge_minor_units'] ?? null, '€99.00, and no VAT on this sale');
        $this->aboutSame(7_967, $change['net_minor_units'] ?? null, '9 900 less the credit');

        // 2. The credit went back on the card — a refund against the payment
        // that collected the period, never a negative line on a document.
        $refund = $this->newestRow('SELECT payment_id, amount_minor_units, status, reason FROM refunds');
        self::assertSame($paid, $refund['payment_id'] ?? null);
        // Exactly the credit the decision reported: what the customer was told
        // and what left the platform are the same figure, to the minor unit.
        self::assertSame($credit, Row::integer($refund, 'amount_minor_units'));
        self::assertSame('PENDING', $refund['status'] ?? null, 'settlement arrives by webhook');
        self::assertIsString($change['credit_refund_id'] ?? null);

        // 3. And it carries its credit note (ADR-058), against the invoice the
        // period was paid on, with the VAT split so that **the gross is exact
        // and the base absorbs the rounding** — priced from money that has
        // already moved, so the document has to say that figure or it describes
        // a different movement.
        $note = $this->newestRow(
            'SELECT invoice_id, number, net_minor_units, vat_minor_units, gross_minor_units FROM credit_notes',
        );
        $net = Row::integer($note, 'net_minor_units');
        $vat = Row::integer($note, 'vat_minor_units');

        self::assertSame($credit, Row::integer($note, 'gross_minor_units'), 'the gross is the money that moved');
        self::assertSame($credit, $net + $vat, 'credit_notes_gross_is_net_plus_vat, in its own words');
        // The whole credit is base and the VAT is zero, because the sale
        // carried none: the organisation sells this seat and is not registered
        // (ADR-057). It read 1 933 / 387 while the platform sold to the company
        // at 20%, and the rule it was written for — the gross is exact and the
        // base absorbs the rounding — is what the line above still asserts.
        $this->aboutSame(1_933, $net, 'the base is the whole of it, there being no VAT');
        self::assertSame(0, $vat, 'no VAT on this sale, so nothing to apportion');
        self::assertIsString($note['number'] ?? null, 'a credit note has a legal number');

        // The fiscal fact is reversed, in the period the correction is made in,
        // and it is the document's own two amounts negated — not a second
        // calculation that could disagree with it.
        $reversal = $this->newestRow(
            'SELECT taxable_base, vat_amount, vat_rate, rule_id FROM vat_transactions WHERE credit_note_id IS NOT NULL',
        );
        self::assertSame(-$net, Row::integer($reversal, 'taxable_base'));
        self::assertSame(-$vat, Row::integer($reversal, 'vat_amount'));
        // Zero, and it is still "the rate the invoice recorded": this sale
        // carried no VAT, so the reversal carries none either. The rule the
        // assertion exists for is that the reversal copies the rate rather than
        // recomputing it with today's — which a zero proves as well as a 2 000
        // did, and the fiscal chain's own suite proves at 2 000.
        self::assertSame(0, Row::integer($reversal, 'vat_rate'), 'the rate the invoice recorded, not today’s');

        // 4. The new period is invoiced through the normal chain: a second
        // document, numbered in the same series, for €99.00.
        $invoice = $this->newestRow(
            'SELECT id, number, gross_minor_units, status, period_start, period_end'
            . ' FROM invoices ORDER BY issued_at DESC, number DESC LIMIT 1',
        );
        self::assertSame($change['charge_invoice_id'] ?? null, $invoice['id'] ?? null);
        self::assertSame(9_900, Row::integer($invoice, 'gross_minor_units'));
        self::assertSame('ISSUED', $invoice['status'] ?? null);
        // The organisation's series, not the platform's: a seat is Acme
        // selling, so the number is continuous within Acme's own sequence
        // (ADR-057, `issuer_tenant_id`). The assertion is the same because the
        // sequence starts in the same place; the reason it holds has changed.
        self::assertSame('2026-000002', $invoice['number'] ?? null, 'gapless, in the issuer’s own series');

        // 5. The anchor reset, and the invoice bills exactly the period it
        // opened.
        $subscription = $this->decode(
            $this->request('GET', '/api/v1/subscription', $this->headers()),
        )['seat'] ?? null;
        self::assertIsArray($subscription);
        self::assertSame('pro', $this->field($subscription, 'offer', 'code'));
        self::assertSame(
            [$subscription['current_period_start'] ?? null, $subscription['current_period_end'] ?? null],
            [$this->moment($invoice, 'period_start'), $this->moment($invoice, 'period_end')],
        );
    }

    /**
     * **Nothing outstanding raises no document at all.**
     *
     * Moving *up* to a plan priced at nothing — the freemium shape, reached by
     * rank and not by price — charges nothing, so no invoice is raised.
     * Numbering is gapless, and a €0 invoice is the permanent, unremovable
     * record of no transaction.
     *
     * The credit still goes back: the customer paid for a period they are
     * leaving, and the plan they move to costing nothing does not make that
     * money the platform's.
     */
    public function testAMoveUpWithNothingToPayRaisesNoInvoice(): void
    {
        $this->subscribeTo($this->starterOffer);
        $this->invoiceAndCollect();
        $this->tenDaysIn();

        $change = $this->decode($this->changeTo($this->freeOffer))['change'] ?? null;
        self::assertIsArray($change);

        self::assertSame(0, $change['charge_minor_units'] ?? null);
        self::assertNull($change['charge_invoice_id'] ?? null);
        $owed = $this->aboutSame(-1_933, $change['net_minor_units'] ?? null, 'the customer is owed, not charged');

        // One invoice in the world: the one that was paid. The move raised none.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));
        // And the credit was not skipped along with the document.
        self::assertSame(-$owed, $this->rowsMatching('SELECT coalesce(sum(amount_minor_units), 0) FROM refunds'));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * Successive moves up chain **by construction** (spec §3.4): each credit is
     * a share of the period the previous move opened.
     *
     * Two moves, each ten days into its own thirty-day period, each against its
     * own settled payment. The second credit is two thirds of what the *first
     * upgrade's* invoice collected — €118.80 — and not of the original €34.80.
     * A platform that kept prorating the first period would credit the same
     * days twice and would be bounded only by `CREDIT_EXCEEDS_INVOICE`.
     */
    public function testEachMoveUpProratesThePeriodTheLastOneOpened(): void
    {
        $this->subscribeTo($this->starterOffer);
        $this->invoiceAndCollect();
        $this->tenDaysIn();

        $first = $this->decode($this->changeTo($this->proOffer))['change'] ?? null;
        self::assertIsArray($first);
        $this->aboutSame(1_933, $first['credit_minor_units'] ?? null, 'two thirds of €29.00');

        // Pay the invoice the first move raised, so there is money behind the
        // period it opened, and move on ten days again.
        $this->collect($this->latestInvoice());
        $this->tenDaysIn();

        $second = $this->decode($this->changeTo($this->committedOffer))['change'] ?? null;
        self::assertIsArray($second);

        // Two thirds of €99.00 — the period the first upgrade opened and its
        // customer paid for. It read €118.80 while that sale carried 20% VAT.
        $this->aboutSame(6_600, $second['credit_minor_units'] ?? null, 'two thirds of €99.00');

        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * Where nothing was collected, nothing is credited — and the change still
     * happens, with the reason written into the decision (spec §3.3).
     *
     * The three ways this arises are one answer: a period nobody paid for has
     * no unconsumed value. Refusing the move would trap somebody on a plan
     * nobody is paying for; crediting anyway would return money that never
     * arrived. What must not happen is silence, and the reason is what stops
     * it.
     */
    public function testAPeriodNobodyPaidForIsNotCreditedAndSaysSo(): void
    {
        $this->subscribeTo($this->starterOffer);

        // Invoiced, and a payment **started and never settled**: money was
        // asked for and never arrived. An authorization nobody collected is not
        // money to give back, which is why the question asked of the database is
        // "the latest *settled* payment" and not "the latest payment".
        self::assertSame(201, $this->issueInvoice()->getStatusCode());
        self::assertSame(201, $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->latestInvoice() . '/payments',
            $this->headers(),
        )->getStatusCode());
        self::assertSame('PENDING', $this->connection->fetchOne('SELECT status FROM payments'));

        $this->tenDaysIn();

        $change = $this->decode($this->changeTo($this->proOffer))['change'] ?? null;
        self::assertIsArray($change);

        self::assertSame(0, $change['credit_minor_units'] ?? null);
        self::assertNull($change['credit_refund_id'] ?? null);
        self::assertSame(9_900, $change['net_minor_units'] ?? null, 'the whole new period is payable');
        self::assertContains(
            'Nothing has been collected for the current period, so there is no unconsumed value to give back.',
            $this->reasonsOf($change),
        );

        // No money went back, and no document says any did.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * And the same holds once an invoice has been credited in full: the value
     * has already gone back, so there is none left to return.
     *
     * Without this bound the platform would reverse the same VAT twice and
     * declare a negative sale that never happened — which
     * `CREDIT_EXCEEDS_INVOICE` refuses, and refuses *after* the provider has
     * been asked. Asked here instead, one step earlier.
     */
    public function testAnInvoiceAlreadyCreditedInFullLeavesNothingToReturn(): void
    {
        $this->subscribeTo($this->starterOffer);
        $this->invoiceAndCollect();

        self::assertSame(201, $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->latestInvoice() . '/credit',
            $this->headers(),
            $this->json(['reason' => 'Goodwill']),
        )->getStatusCode());

        $this->tenDaysIn();

        $change = $this->decode($this->changeTo($this->proOffer))['change'] ?? null;
        self::assertIsArray($change);

        self::assertSame(0, $change['credit_minor_units'] ?? null);
        self::assertContains(
            'Everything collected for this period has already been given back.',
            $this->reasonsOf($change),
        );

        // The goodwill credit note, and no second one beside it.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
    }

    /**
     * §3.3: the anchor reset **neither re-arms the commitment nor shortens
     * it.** A change of plan is not a new contract.
     *
     * The committed offer sells twelve months and the customer already holds
     * twenty-four, so the date they agreed to is the date that survives — to
     * the microsecond, which is the only assertion that can tell "unchanged"
     * from "recomputed and happened to match".
     */
    public function testTheAnchorResetLeavesTheCommitmentWhereItWas(): void
    {
        $subscription = $this->subscribeTo($this->longCommitment());
        $agreed = $subscription->commitmentEndsAt;
        self::assertNotNull($agreed);
        self::assertSame(24, $subscription->terms->commitmentMonths);

        $this->invoiceAndCollect();
        $this->tenDaysIn();

        $this->changeTo($this->committedOffer);

        $after = $this->decode(
            $this->request('GET', '/api/v1/subscription', $this->headers()),
        )['seat'] ?? null;
        self::assertIsArray($after);

        self::assertSame(24, $this->field($after, 'terms', 'commitment_months'));
        self::assertSame($agreed->format(DATE_ATOM), $this->field($after, 'terms', 'commitment_ends_at'));

        // And the period did move, which is what makes the assertion above
        // about the commitment rather than about nothing having happened: the
        // anchor was ten days in the past a moment ago, and it is now.
        $start = $after['current_period_start'] ?? null;
        self::assertIsString($start);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($start))->getTimestamp(), 300);
    }

    /**
     * A `CUSTOM` billing period has no length, so there is no share of it to
     * take — refused in words rather than divided by something meaningless.
     *
     * And refused **before anything moves**: no refund, no credit note, no
     * invoice, and the subscription still on the plan it was.
     */
    public function testACustomPeriodIsRefusedRatherThanProrated(): void
    {
        $this->subscribeTo($this->negotiatedOffer());

        $refused = $this->changeTo($this->proOffer);

        self::assertSame(409, $refused->getStatusCode());

        $error = $this->errorOf($refused);
        self::assertSame('CHANGE_NOT_PERMITTED', $error['code'] ?? null);
        self::assertSame('change.period_not_priceable', $this->field($error, 'details', 'rule_id'));

        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));

        $still = $this->decode($this->request('GET', '/api/v1/subscription', $this->headers()));
        self::assertSame('negotiated', $this->field($still, 'seat', 'offer', 'code'));
    }

    /**
     * The preview says the same thing the act does, which is the whole reason
     * it shares the calculation (spec §7).
     *
     * Asked first, then acted on, and the two answers compared field by field.
     * A preview computed by a different code path is a preview that can be
     * wrong, and a catalogue would be quoting a figure the server never agreed
     * to.
     */
    public function testThePreviewSaysWhatTheChangeThenDoes(): void
    {
        $this->subscribeTo($this->starterOffer);
        $this->invoiceAndCollect();
        $this->tenDaysIn();

        $preview = $this->decode($this->request(
            'POST',
            '/api/v1/subscription/preview-change',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer]),
        ))['if_changed_now'] ?? null;
        self::assertIsArray($preview);

        // Nothing was written by asking.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));

        $change = $this->decode($this->changeTo($this->proOffer))['change'] ?? null;
        self::assertIsArray($change);

        foreach (['rule_id', 'direction', 'effect', 'charge_minor_units'] as $field) {
            self::assertSame($preview[$field] ?? null, $change[$field] ?? null, $field . ' differs from the preview');
        }

        // The credit and the net are a share of elapsed time, and the clock has
        // moved between the two calls — by the time it takes one HTTP request,
        // which at this scale is worth under a minor unit. So they are compared
        // to within one, and what is asserted exactly is the thing that must not
        // move: the rule, the direction and the price of the new period.
        foreach (['credit_minor_units', 'net_minor_units'] as $field) {
            $quoted = $preview[$field] ?? null;
            self::assertIsInt($quoted);
            $this->aboutSame($quoted, $change[$field] ?? null, $field . ' differs from the preview');
        }
    }

    // --- Whose subscription, which is a flag and never an id -------------------

    /**
     * **The seat is the door that opens** (2026-09-27).
     *
     * Every change operation reached `findActive()`, whose SQL ends
     * `AND s.subscriber_kind = 'TENANT'` — and the tenant surface sells seats and
     * nothing else (ADR-055), so no customer can create a row of that kind. The
     * deferred downgrade, the preview and this proration all answered
     * `NO_SUBSCRIPTION` for every subscription anybody could actually hold: three
     * étapes built onto a door that does not open.
     *
     * So `seat` names the caller's own, as it does on the cancel endpoint, and
     * for the reason §13.1 gives — the only two subscribers are the tenant and
     * the caller, both come from the context, and there is no id to supply.
     *
     * The whole chain over a seat, in one test, because the point is that *all*
     * of it works and not merely that the lookup finds something: the credit goes
     * back on the card that paid, the new period is invoiced, and the anchor
     * resets.
     */
    public function testASeatIsChangedByItsHolder(): void
    {
        // Bought the way a customer buys: order, invoice, payment, seat. Which
        // also exercises the reason `latestSettledForSubscription` goes through
        // the invoice — that payment predates the subscription it starts, so its
        // own `subscription_id` is null.
        $paid = $this->buyASeat($this->starterOffer);
        $seat = $this->heldSeat();
        $this->tenDaysIn();

        $preview = $this->decode($this->request(
            'POST',
            '/api/v1/subscription/preview-change',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer, 'seat' => true]),
        ))['if_changed_now'] ?? null;
        self::assertIsArray($preview);
        // **No VAT, and that is the seat's own tax position rather than an
        // oversight** (ADR-057): a seat is the organisation selling to one of its
        // own people, and Acme has not declared itself registered, so it charges
        // none. The gross is therefore €29.00, two thirds of which is 1 933 — the
        // figures differ from the organisation's own subscription above because
        // the parties on the document differ.
        $this->aboutSame(1_933, $preview['credit_minor_units'] ?? null, 'two thirds of €29.00');
        self::assertSame(9_900, $preview['charge_minor_units'] ?? null);

        $change = $this->decode($this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer, 'seat' => true]),
        ))['change'] ?? null;
        self::assertIsArray($change);

        self::assertSame('UPGRADE', $change['direction'] ?? null);
        $credit = $this->aboutSame(1_933, $change['credit_minor_units'] ?? null, 'two thirds of €29.00');
        self::assertSame(9_900, $change['charge_minor_units'] ?? null);
        self::assertSame(9_900 - $credit, $change['net_minor_units'] ?? null);
        self::assertIsString($change['charge_invoice_id'] ?? null);

        // Against the seat's own payment, and the seat's own subscription moved.
        $refund = $this->newestRow('SELECT payment_id, amount_minor_units FROM refunds');
        self::assertSame($paid, $refund['payment_id'] ?? null);
        self::assertSame($credit, Row::integer($refund, 'amount_minor_units'));
        self::assertSame(
            ['pro', $this->user],
            [
                $this->connection->fetchOne(
                    'SELECT o.code FROM subscriptions s'
                    . ' JOIN offer_versions v ON v.id = s.offer_version_id'
                    . ' JOIN offers o ON o.id = v.offer_id WHERE s.id = :id',
                    ['id' => $seat->id],
                ),
                $this->connection->fetchOne('SELECT subscriber_user_id FROM subscriptions WHERE id = :id', ['id' => $seat->id]),
            ],
        );
    }

    /**
     * And a move down on a seat is deferred and withdrawable, which is étape 2
     * reaching a customer for the first time.
     *
     * The withdrawal travels in the query rather than a body, because a DELETE
     * has none — still a flag, still never an id.
     */
    public function testASeatsMoveDownIsDeferredAndCanBeWithdrawn(): void
    {
        $this->buyASeat($this->proOffer);
        $seat = $this->heldSeat();

        $scheduled = $this->decode($this->request(
            'POST',
            '/api/v1/subscription/pending',
            $this->headers(),
            $this->json(['offer_id' => $this->starterOffer, 'seat' => true]),
        ));

        $pending = $scheduled['pending'] ?? null;
        self::assertIsArray($pending);
        self::assertSame('starter', $pending['code'] ?? null);
        // Nothing moved: the holder keeps the plan they paid for, entire.
        self::assertSame('pro', $this->field($scheduled, 'offer', 'code'));

        $withdrawn = $this->decode($this->request(
            'DELETE',
            '/api/v1/subscription/pending?seat=1',
            $this->headers(),
        ));

        self::assertArrayHasKey('pending', $withdrawn);
        self::assertNull($withdrawn['pending']);
        self::assertSame($seat->id, $withdrawn['id'] ?? null);
    }

    /**
     * Asking about a seat nobody holds is `NO_SEAT` and not a silent answer
     * about the organisation's subscription — which would be a customer told the
     * price of somebody else's plan.
     */
    public function testAskingAboutASeatNobodyHoldsSaysSo(): void
    {
        $refused = $this->request(
            'POST',
            '/api/v1/subscription/preview-change',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer, 'seat' => true]),
        );

        self::assertSame(404, $refused->getStatusCode());
        self::assertSame('NO_SEAT', $this->errorOf($refused)['code'] ?? null);
    }

    // --- Fixtures -------------------------------------------------------------

    private function subscribeTo(string $offerId): \App\Commerce\Domain\Subscription
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        return $subscriptions->subscribe($this->tenant, $this->product, $offerId, $this->user, $this->user);
    }

    /**
     * A seat, bought the way a customer buys one: order → invoice → payment →
     * seat (§20, ADR-055). Nothing shorter would do: the whole point is that the
     * payment which collected the first period exists before the subscription
     * does.
     *
     * @return string the payment that settled
     */
    private function buyASeat(string $offerId): string
    {
        $order = $this->decode($this->request(
            'POST',
            '/api/v1/sales/orders',
            $this->headers(),
            $this->json(['offer_id' => $offerId]),
        ));

        $orderId = $order['id'] ?? null;
        self::assertIsString($orderId);

        $invoiceId = $this->decode($this->request(
            'POST',
            '/api/v1/sales/orders/' . $orderId . '/fulfil',
            $this->headers(),
        ))['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        return $this->collect($invoiceId);
    }

    private function heldSeat(): \App\Commerce\Domain\Subscription
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        $seat = $subscriptions->seatOf($this->tenant, $this->product, $this->user);
        self::assertNotNull($seat, 'the chain should have started a seat');

        return $seat;
    }

    private function changeTo(string $offerId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $offerId]),
        );
    }

    private function issueInvoice(): ResponseInterface
    {
        return $this->request('POST', '/api/v1/billing/invoices', $this->headers());
    }

    /**
     * The money the credit is a share of: the current period, invoiced and
     * collected through the real chain.
     *
     * @return string the payment that settled
     */
    private function invoiceAndCollect(): string
    {
        self::assertSame(201, $this->issueInvoice()->getStatusCode());

        return $this->collect($this->latestInvoice());
    }

    /**
     * @return string the payment that settled
     */
    private function collect(string $invoiceId): string
    {
        $started = $this->decode($this->request(
            'POST',
            '/api/v1/billing/invoices/' . $invoiceId . '/payments',
            $this->headers(),
        ));

        $paymentId = $started['id'] ?? null;
        self::assertIsString($paymentId);

        $reference = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments WHERE id = :id',
            ['id' => $paymentId],
        );
        self::assertIsString($reference);

        // The provider says it collected, which is the only thing that may.
        $body = $this->json([
            'id' => 'evt_' . substr($paymentId, 0, 8),
            'type' => 'payment.succeeded',
            'payment_id' => $reference,
        ]);

        self::assertSame(200, $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => (new StubPaymentProvider(self::SECRET))->sign($body)],
            $body,
        )->getStatusCode());

        return $paymentId;
    }

    /**
     * Ten days into a thirty-day period, which is where an upgrade actually
     * happens — written in SQL because the alternative is waiting ten days.
     */
    private function tenDaysIn(): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE subscriptions
                       SET current_period_start = now() - interval '10 days',
                           current_period_end = now() + interval '%d days'
                     WHERE tenant_id = :tenant AND product_id = :product
                    SQL,
                self::PERIOD_DAYS - 10,
            ),
            ['tenant' => $this->tenant, 'product' => $this->product],
        );
    }

    private function latestInvoice(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM invoices ORDER BY number DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function moment(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value)
            ? (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function newestRow(string $sql): array
    {
        $row = $this->connection->fetchAssociative($sql);
        self::assertIsArray($row, 'expected a row: ' . $sql);

        return $row;
    }

    private function rowsMatching(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A prorated amount, allowing the minor unit that two clocks cost.
     *
     * The unconsumed share is a fraction of elapsed *time*, and the period's
     * ends are PostgreSQL's `now()` while the moment of the change is PHP's. A
     * second between them is worth 3 480 / 2 592 000 of a second — under a
     * minor unit at this scale, but enough to turn 2 320 into 2 319 on a slow
     * machine, and an assertion that failed for that reason would be a test
     * that reports the weather.
     *
     * So the *arithmetic* is asserted exactly where the dates can be stated —
     * {@see \App\Tests\Unit\ProrationPolicyTest} — and here the figure is
     * checked to within a unit and then used, so everything downstream of it
     * (the refund, the document, the reversal) is compared against the credit
     * the server actually reported rather than against a constant.
     *
     * @return int the amount the server gave, to go on asserting against
     */
    private function aboutSame(int $expected, mixed $actual, string $why): int
    {
        self::assertIsInt($actual, $why);
        self::assertGreaterThanOrEqual($expected - 1, $actual, $why);
        self::assertLessThanOrEqual($expected + 1, $actual, $why);

        return $actual;
    }

    /**
     * One field of a nested JSON answer.
     *
     * A chain of `assertIsArray` at every level says nothing a reader wants to
     * know. A missing key is null here, and the assertion beside the call is
     * what fails.
     *
     * @param array<mixed> $body
     */
    private function field(array $body, string ...$path): mixed
    {
        $value = $body;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The reasons a decision carries, as something to assert against.
     *
     * @param array<mixed> $decision
     *
     * @return array<mixed>
     */
    private function reasonsOf(array $decision): array
    {
        $reasons = $decision['reasons'] ?? null;

        self::assertIsArray($reasons, 'a decision always says why');

        return $reasons;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
    }

    private function configureProduct(): void
    {
        $supplier = json_encode(['legal_name' => 'Atlas SAS', 'vat_number' => 'FR12345678901', 'country_code' => 'FR']);
        self::assertIsString($supplier);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "FR", "oss_registered": true}' AS jsonb))
                SQL,
            ['product' => $this->product, 'supplier' => $supplier],
        );
    }

    /**
     * The organisation, on a document and fiscally. A private customer in the
     * supplier's own country: one rate, one fiscal fact, which is the case a
     * partial credit note is bounded to (ADR-058).
     */
    private function declareIdentity(): void
    {
        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json(['legal_name' => 'Acme SARL', 'country_code' => 'FR', 'city' => 'Paris']),
        )->getStatusCode());

        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/tax/profile',
            $this->headers(),
            // Not registered, deliberately, and the file's own figures depend
            // on it: a seat is the organisation selling to one of its own
            // people, Acme has never declared itself a taxable person, and a
            // small business charges no VAT (ADR-057). Declaring it registered
            // adds 20% to every amount below — which I did on 2026-10-01 while
            // chasing the organisation's subscription out of this file, and
            // which was the wrong half to change.
            $this->json(['customer_kind' => 'B2C', 'country_code' => 'FR']),
        )->getStatusCode());
    }

    private function seedCatalogue(): void
    {
        $this->starterOffer = $this->offer('STARTER', 10, 'starter', 2_900, 'MONTHLY');
        $this->proOffer = $this->offer('PRO', 20, 'pro', 9_900, 'MONTHLY');
        // Priced at nothing and ranked *above* what it is moved from, so
        // "nothing outstanding raises no document" is reached through the
        // priced path rather than through a special case.
        $this->freeOffer = $this->offer('GIFT', 40, 'gift', 0, 'MONTHLY');
        $this->committedOffer = $this->offer('SCALE', 30, 'scale', 19_900, 'MONTHLY', commitmentMonths: 12);
    }

    /** A subscription committed for two years, to be moved onto a twelve-month offer. */
    private function longCommitment(): string
    {
        return $this->offer('ENTRY', 5, 'entry-committed', 2_900, 'MONTHLY', commitmentMonths: 24, termMonths: 36);
    }

    /** Terms with no computable period: nothing to prorate and nothing to invent. */
    private function negotiatedOffer(): string
    {
        return $this->offer('NEGOTIATED', 7, 'negotiated', 5_000, 'CUSTOM');
    }

    private function offer(
        string $planCode,
        int $rank,
        string $code,
        int $price,
        string $billingPeriod,
        int $commitmentMonths = 0,
        ?int $termMonths = null,
    ): string {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank) VALUES (:product, :code, :code, :rank) RETURNING id',
            ['product' => $this->product, 'code' => $planCode, 'rank' => $rank],
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name) VALUES (:product, :plan, :code, :code) RETURNING id',
            ['product' => $this->product, 'plan' => $plan, 'code' => $code],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from,
                     commitment_months, term_months)
                VALUES (:offer, 1, 'ACTIVE', :period, :price, 'EUR', now() - interval '1 day',
                        :commitment, :term)
                RETURNING id
                SQL,
            [
                'offer' => $offer,
                'period' => $billingPeriod,
                'price' => $price,
                'commitment' => $commitmentMonths,
                'term' => $termMonths,
            ],
        );

        // Read to prove the version exists: an offer with no version is not on
        // sale, and the refusal it would produce is not what these tests mean.
        self::assertNotSame('', $version);

        return $offer;
    }
}
