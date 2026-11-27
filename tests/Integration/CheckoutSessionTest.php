<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Job\Service\JobRunner;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentProvider;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\ProviderRefund;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Money\Money;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\RecordingPaymentProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Checkout, against the real chain (§7's Checkout block).
 *
 * The claim worth testing is not that three calls became one. It is that
 * becoming one changed nothing else: the offer must still be on sale, the
 * tenant must still have a billing profile, the invoice is still numbered
 * gaplessly, and **the subscription still waits for the money**. A checkout
 * that activated on creation would extend credit to everyone who can reach
 * the endpoint, and that is exactly the regression this guards.
 *
 * There is no `checkout_sessions` table, so there is also nothing here
 * asserting one stayed in step with the order — which is the point of not
 * having built one.
 */
#[CoversNothing]
final class CheckoutSessionTest extends DatabaseApiTestCase
{
    private const SECRET = 'checkout-test-secret';

    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $offer = '';

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

        $this->configure();
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
                    ['billing.read', 'billing.manage', 'billing.pay', 'sales.read', 'sales.manage', 'subscription.read'],
                ),
            ]),
        ]);

        $this->saveProfile();
    }

    // --- What one call does -------------------------------------------------

    public function testOneCallOrdersInvoicesAndAuthorizes(): void
    {
        $response = $this->open();

        self::assertSame(201, $response->getStatusCode());

        $session = $this->sessionOf($response);
        self::assertSame('AWAITING_PAYMENT', $session['status'] ?? null);
        self::assertIsString($session['invoice_id'] ?? null);
        self::assertIsString($session['payment_id'] ?? null);
        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $session['gross'] ?? null);

        // Issued once and never stored, so it is here and nowhere else.
        self::assertIsString($session['client_secret'] ?? null);
    }

    public function testTheSubscriptionWaitsForTheMoney(): void
    {
        $session = $this->sessionOf($this->open());

        // The whole point of the split fulfilment (M6.3). An order that
        // activated here would be credit extended to anyone with the
        // permission to press the button.
        self::assertArrayHasKey('subscription_id', $session);
        self::assertNull($session['subscription_id']);

        $count = $this->connection->fetchOne('SELECT count(*) FROM subscriptions');
        self::assertSame('0', (string) (is_scalar($count) ? $count : 'not counted'));
    }

    public function testTheSessionIdIsTheOrderId(): void
    {
        $session = $this->sessionOf($this->open());

        self::assertSame($session['id'] ?? null, $session['order_id'] ?? null);

        // And it addresses a real order, which is what makes a second table
        // unnecessary rather than merely absent.
        $id = $session['id'];
        self::assertIsString($id);

        $exists = $this->connection->fetchOne('SELECT count(*) FROM orders WHERE id = :id', ['id' => $id]);
        self::assertSame('1', (string) (is_scalar($exists) ? $exists : 'not counted'));
    }

    public function testTheSessionCanBeReadBackWithoutItsSecret(): void
    {
        $opened = $this->sessionOf($this->open());
        $id = $opened['id'];
        self::assertIsString($id);

        $read = $this->sessionOf($this->request(
            'GET',
            '/api/v1/checkout/sessions/' . $id,
            $this->headers(),
        ));

        self::assertSame('AWAITING_PAYMENT', $read['status'] ?? null);
        self::assertSame($opened['payment_id'] ?? null, $read['payment_id'] ?? null);
        // Nothing to return it from: it was never stored.
        self::assertArrayNotHasKey('client_secret', $read);
    }

    // --- What is refused ----------------------------------------------------

    public function testAnOfferThatIsNotOnSaleIsNotBuyable(): void
    {
        // A published version never returns to DRAFT; the fixture forges one
        // that was never published.
        TestDatabase::forge(
            $this->connection,
            ['offer_versions'],
            "UPDATE offer_versions SET status = 'DRAFT' WHERE offer_id = :offer",
            ['offer' => $this->offer],
        );

        $response = $this->open();

        self::assertSame(404, $response->getStatusCode());
    }

    public function testATenantWithNoBillingProfileCannotCheckOut(): void
    {
        $this->connection->executeStatement('DELETE FROM billing_profiles');

        $response = $this->open();

        // Refused before any document is raised: numbering is gapless, so an
        // invoice created by mistake cannot be deleted afterwards.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($response)['code'] ?? null);

        $invoices = $this->connection->fetchOne('SELECT count(*) FROM invoices');
        self::assertSame('0', (string) (is_scalar($invoices) ? $invoices : 'not counted'));
    }

    public function testASecondCheckoutIsRefusedWhileTheSeatIsLive(): void
    {
        $first = $this->sessionOf($this->open());
        $this->pay($first, 'evt_first');
        self::assertSame('COMPLETED', $this->sessionOf($this->show($first))['status'] ?? null);

        $response = $this->open();

        // 409, before any document: the operator's own deployment showed
        // what the alternative costs — a numbered invoice, a charged card,
        // and a webhook that can never be honoured.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SEAT_ALREADY_ACTIVE', $this->errorOf($response)['code'] ?? null);

        $orders = $this->connection->fetchOne('SELECT count(*) FROM orders');
        self::assertSame('1', (string) (is_scalar($orders) ? $orders : 'not counted'));
        $invoices = $this->connection->fetchOne('SELECT count(*) FROM invoices');
        self::assertSame('1', (string) (is_scalar($invoices) ? $invoices : 'not counted'));
    }

    // --- Giving up before paying (2026-09-18) ------------------------------------

    public function testAnUnpaidCheckoutCanBeGivenUpAndTakesItsInvoiceWithIt(): void
    {
        $session = $this->sessionOf($this->open());
        self::assertIsString($session['invoice_id']);

        $response = $this->cancel($session);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('CANCELLED', $this->sessionOf($response)['status'] ?? null);
        self::assertSame('CANCELLED', $this->sessionOf($this->show($session))['status'] ?? null);

        // The invoice is cancelled, not deleted: its number stays, as a
        // cancelled document, because the sequence has no gaps (§26).
        $invoice = $this->connection->fetchAssociative(
            'SELECT status, number FROM invoices WHERE id = :id',
            ['id' => $session['invoice_id']],
        );
        self::assertIsArray($invoice);
        self::assertSame('CANCELLED', $invoice['status']);
        self::assertIsString($invoice['number']);

        // And nothing collectable is left: a new attempt on it is refused.
        $attempt = $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $session['invoice_id'] . '/payments',
            $this->headers(),
        );
        self::assertSame(409, $attempt->getStatusCode());
        self::assertSame('INVOICE_NOT_PAYABLE', $this->errorOf($attempt)['code'] ?? null);
        $count = $this->connection->fetchOne('SELECT count(*) FROM subscriptions');
        self::assertSame('0', (string) (is_scalar($count) ? $count : 'not counted'));
    }

    /**
     * The order's invoice was issued, and its VAT declared, when the checkout
     * opened. Giving up withdraws it, and since 2026-10-26 the declaration
     * follows: the invoice's facts are reversed, dated today, so the return
     * nets to nothing instead of keeping a sale that never happened.
     */
    public function testGivingUpReversesTheVatTheInvoiceDeclared(): void
    {
        $session = $this->sessionOf($this->open());
        self::assertIsString($session['invoice_id']);

        $declared = $this->vatOf($session['invoice_id']);
        self::assertGreaterThan(0, $declared['rows'], 'The order declared its VAT when it was issued.');

        self::assertSame(200, $this->cancel($session)->getStatusCode());

        $after = $this->vatOf($session['invoice_id']);
        self::assertSame(2 * $declared['rows'], $after['rows'], 'One reversal per fact, and nothing rewritten.');
        self::assertSame(0, $after['vat']);
        self::assertSame(0, $after['base']);
    }

    /**
     * @return array{rows: int, vat: int, base: int}
     */
    private function vatOf(string $invoiceId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) AS rows, coalesce(sum(vat_amount), 0) AS vat, coalesce(sum(taxable_base), 0) AS base
               FROM vat_transactions WHERE invoice_id = :id',
            ['id' => $invoiceId],
        );
        self::assertIsArray($row);

        return [
            'rows' => is_numeric($row['rows']) ? (int) $row['rows'] : -1,
            'vat' => is_numeric($row['vat']) ? (int) $row['vat'] : -1,
            'base' => is_numeric($row['base']) ? (int) $row['base'] : -1,
        ];
    }

    public function testAPaidCheckoutCannotBeGivenUp(): void
    {
        $session = $this->sessionOf($this->open());
        $this->pay($session, 'evt_paid');

        $response = $this->cancel($session);

        // Completed, so nothing to give up: undoing a sale is a credit note
        // and a cancellation, each a deliberate act with its own document.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CHECKOUT_NOT_CANCELLABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testAGivenUpCheckoutCannotBeGivenUpTwice(): void
    {
        $session = $this->sessionOf($this->open());
        $this->cancel($session);

        $again = $this->cancel($session);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('CHECKOUT_NOT_CANCELLABLE', $this->errorOf($again)['code'] ?? null);
    }

    // --- Money after a cancellation (ADR-076) -----------------------------------

    /**
     * Cancelling used to ask one thing — "has it settled?" — and void the
     * invoice under an attempt that could still collect. The attempt is now
     * abandoned at the provider first, so the success that arrives later is
     * about a payment that can no longer be one.
     */
    public function testGivingUpAbandonsTheAttemptSoALateSuccessCollectsNothing(): void
    {
        $session = $this->sessionOf($this->open());
        self::assertIsString($session['payment_id']);

        self::assertSame(200, $this->cancel($session)->getStatusCode());

        $abandoned = $this->connection->fetchAssociative(
            'SELECT status, failure_code FROM payments WHERE id = :id',
            ['id' => $session['payment_id']],
        );
        self::assertIsArray($abandoned);
        self::assertSame('CANCELLED', $abandoned['status']);
        self::assertSame('SUPERSEDED', $abandoned['failure_code']);

        // The provider's late word is taken and recorded stale — 202, taken
        // and not applied: no money is marked collected, and nothing starts.
        self::assertSame(202, $this->pay($session, 'evt_after_cancel')->getStatusCode());
        self::assertSame('CANCELLED', $this->connection->fetchOne(
            'SELECT status FROM payments WHERE id = :id',
            ['id' => $session['payment_id']],
        ));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM subscriptions'));
    }

    /**
     * A debit already in processing cannot be stopped, and that money is
     * coming: the purchase it pays must still be there when it does.
     */
    public function testAnAttemptTheProviderCannotStopRefusesTheCancellation(): void
    {
        $session = $this->sessionOf($this->open());

        $this->override([PaymentProviders::class => new PaymentProviders([
            new RecordingPaymentProvider(new StubPaymentProvider(self::SECRET), false),
        ])]);

        $refused = $this->cancel($session);

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('PAYMENT_STILL_IN_FLIGHT', $this->errorOf($refused)['code'] ?? null);
        self::assertSame('AWAITING_PAYMENT', $this->sessionOf($this->show($session))['status'] ?? null);
        self::assertSame('ISSUED', $this->connection->fetchOne(
            'SELECT status FROM invoices WHERE id = :id',
            ['id' => $session['invoice_id']],
        ));

        // And when it lands, it settles what it was for.
        self::assertSame(200, $this->pay($session, 'evt_in_flight')->getStatusCode());
        self::assertSame('1', $this->scalar('SELECT count(*) FROM subscriptions'));
    }

    /**
     * What the door above cannot reach: an invoice voided by another path
     * while an attempt was live. The money that then settles against a void
     * document goes back by itself — through the runner, which is what cron
     * calls — with no credit note, because the sale was already undone when
     * the invoice was cancelled.
     */
    public function testMoneyThatSettledAgainstAVoidInvoiceGoesBackByItself(): void
    {
        $session = $this->sessionOf($this->open());
        self::assertIsString($session['invoice_id']);

        // Voided underneath the live attempt, as only another path could.
        $this->connection->executeStatement(
            "UPDATE invoices SET status = 'CANCELLED' WHERE id = :id",
            ['id' => $session['invoice_id']],
        );

        self::assertSame(200, $this->pay($session, 'evt_on_void')->getStatusCode());
        self::assertSame('SUCCEEDED', $this->connection->fetchOne(
            'SELECT status FROM payments WHERE id = :id',
            ['id' => $session['payment_id']],
        ));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM subscriptions'));

        $runner = $this->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        $runner->runOnce(100);

        $refund = $this->connection->fetchAssociative(
            'SELECT r.amount_minor_units, p.amount_minor_units AS collected, r.credit_note_id'
            . ' FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE p.id = :id',
            ['id' => $session['payment_id']],
        );
        self::assertIsArray($refund, 'the money should be on its way back');
        self::assertEquals($refund['collected'], $refund['amount_minor_units']);
        self::assertNull($refund['credit_note_id']);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM credit_notes'));

        // A second pass finds it already going back.
        $this->connection->executeStatement("DELETE FROM jobs WHERE type = 'payment.return_unowed'");
        $runner->runOnce(100);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM refunds'));
    }

    private function scalar(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return is_scalar($value) ? (string) $value : 'not counted';
    }

    // --- A seat of one's own (2026-09-18) ----------------------------------------

    public function testWhatIsBoughtIsASeatAndItBindsThePerson(): void
    {
        // Alice reads French, and the document says so (ADR-050): it is
        // addressed to her.
        //
        // The organisation's own purchase stood beside this one until
        // 2026-09-25 and is gone with the sale that made it — so what was a
        // comparison of two documents is now one document, checked harder.
        $this->connection->executeStatement("UPDATE users SET locale = 'fr' WHERE auth_subject = 'sub-alice'");

        $response = $this->open();

        self::assertSame(201, $response->getStatusCode());
        $seat = $this->sessionOf($response);
        self::assertTrue($seat['seat'] ?? null);
        // What it is, said: the offer's own line, not three amounts alone.
        self::assertIsString($seat['description'] ?? null);
        self::assertStringContainsString('Pro', $seat['description']);

        $this->pay($seat, 'evt_seat');
        $read = $this->sessionOf($this->show($seat));
        self::assertSame('COMPLETED', $read['status'] ?? null);
        self::assertIsString($read['subscription_id'] ?? null);

        // The invoice says what was sold, in words, and whom it is for (2026-09-19).
        self::assertIsString($seat['invoice_id']);
        self::assertIsString($seat['id']);
        $invoice = $this->decode($this->request('GET', '/api/v1/billing/invoices/' . $seat['invoice_id'], $this->headers()));
        $lines = $invoice['lines'] ?? null;
        self::assertIsArray($lines);
        $line = $lines[0] ?? null;
        self::assertIsArray($line);
        $offer = $line['offer'] ?? null;
        self::assertIsArray($offer);
        self::assertSame(['code' => 'atlas', 'name' => 'Atlas'], $offer['product'] ?? null);
        self::assertSame('Atlas Pro', $offer['name'] ?? null);
        self::assertSame('Pro', $offer['plan'] ?? null);
        self::assertSame('MONTHLY', $offer['billing_period'] ?? null);
        $customer = $invoice['customer'] ?? null;
        self::assertIsArray($customer);
        // Named as the identity chain knows them — the address it recorded
        // for the subject, which the fake provider derives from the token.
        $person = $customer['person'] ?? null;
        self::assertIsArray($person);
        self::assertIsString($person['email'] ?? null);
        self::assertStringContainsString('alice', $person['email']);
        self::assertSame($person['email'], $person['name'] ?? null);
        // Who sells and who buys: a seat is the organisation selling to one
        // of its people, so the organisation is the supplier and the person
        // the customer (2026-09-19).
        $supplier = $invoice['supplier'] ?? null;
        self::assertIsArray($supplier);
        self::assertSame('Acme SARL', $supplier['legal_name'] ?? null);
        self::assertSame($person['name'], $customer['legal_name'] ?? null);
        // And it is Acme's own series it is numbered in, not the platform's
        // (ADR-054): Acme is the supplier on it.
        self::assertSame(
            $this->tenant,
            $this->connection->fetchOne('SELECT issuer_tenant_id FROM invoices WHERE id = ?', [$seat['invoice_id']]),
        );
        // And the order says for whom.
        self::assertTrue($this->decode($this->request('GET', '/api/v1/sales/orders/' . $seat['id'], $this->headers()))['seat'] ?? null);
        // The document remembers the language it was issued in (ADR-050).
        self::assertSame('fr', $this->connection->fetchOne('SELECT locale FROM invoices WHERE id = ?', [$seat['invoice_id']]));

        // The subscription the seat started binds the person (§13.1).
        $bound = $this->connection->fetchAssociative(
            'SELECT subscriber_user_id FROM subscriptions WHERE id = :id',
            ['id' => $read['subscription_id']],
        );
        self::assertSame(['subscriber_user_id' => $this->user], $bound);

        // One row, and it is hers. Nothing was sold to Acme — and since
        // 2026-10-01 the schema cannot express that sale either: the count
        // beside this one read `subscriber_kind = 'TENANT'`, and the column is
        // gone. What is asserted instead is that every row names a person,
        // which is the same claim the constraint now makes for us.
        $live = $this->connection->fetchOne("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'");
        self::assertSame('1', (string) (is_scalar($live) ? $live : 'not counted'));
        $toNobody = $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE subscriber_user_id IS NULL');
        self::assertSame('0', (string) (is_scalar($toNobody) ? $toNobody : 'not counted'));
    }

    public function testASecondSeatIsRefusedWhileTheFirstIsLive(): void
    {
        $first = $this->sessionOf($this->open());
        $this->pay($first, 'evt_seat');

        $again = $this->open();
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('SEAT_ALREADY_ACTIVE', $this->errorOf($again)['code'] ?? null);
    }

    public function testASessionPaidAfterTheSubscriptionStartedReadsHeld(): void
    {
        // Two checkouts open before either is paid: the one race the
        // refusal at placement cannot reach.
        $first = $this->sessionOf($this->open());
        $second = $this->sessionOf($this->open());

        $this->pay($first, 'evt_first');
        self::assertSame(200, $this->pay($second, 'evt_second')->getStatusCode());

        $read = $this->sessionOf($this->show($second));

        // Not AWAITING_PAYMENT — the card was charged — and not COMPLETED —
        // nothing started. The customer reads the one word that is true.
        self::assertSame('HELD', $read['status'] ?? null);
        self::assertSame('SUCCEEDED', $read['payment_status'] ?? null);
        self::assertArrayHasKey('subscription_id', $read);
        self::assertNull($read['subscription_id']);

        $subscriptions = $this->connection->fetchOne('SELECT count(*) FROM subscriptions');
        self::assertSame('1', (string) (is_scalar($subscriptions) ? $subscriptions : 'not counted'));
    }

    public function testAnotherTenantsSessionIsNotFound(): void
    {
        $id = $this->sessionOf($this->open())['id'];
        self::assertIsString($id);

        // A different tenant with the same product and the same permissions.
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id"),
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'billing.pay', 'sales.read', 'sales.manage'],
                ),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/checkout/sessions/' . $id, $this->headers());

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Retrying -----------------------------------------------------------

    public function testAPaymentStillInFlightIsNotRetried(): void
    {
        $paymentId = $this->sessionOf($this->open())['payment_id'];
        self::assertIsString($paymentId);

        $response = $this->retry($paymentId);

        // It may yet settle. Authorizing a second one now risks collecting
        // twice for one debt.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PAYMENT_STILL_IN_FLIGHT', $this->errorOf($response)['code'] ?? null);
    }

    public function testAFailedPaymentIsRetriedAsANewAttempt(): void
    {
        $session = $this->sessionOf($this->open());
        $paymentId = $session['payment_id'];
        self::assertIsString($paymentId);

        $this->connection->executeStatement(
            // `payments_failed_has_reason` requires a `failure_code` on any
            // FAILED payment: a failure nobody can name is a dead end for
            // whoever has to explain it to the customer. The prose reason is
            // set alongside it because a code alone explains nothing.
            <<<'SQL'
                UPDATE payments
                   SET status = 'FAILED', failed_at = now(),
                       failure_code = 'card_declined', failure_reason = 'The card was declined.'
                 WHERE id = :id
                SQL,
            ['id' => $paymentId],
        );

        $response = $this->retry($paymentId);

        self::assertSame(201, $response->getStatusCode());

        $retried = $this->decode($response);
        self::assertIsString($retried['client_secret'] ?? null);
        // A new row, not the old one revived: PaymentStatus is one-way
        // because the customer may have used a different instrument.
        self::assertNotSame($paymentId, $retried['id'] ?? null);

        $attempts = $this->connection->fetchOne(
            'SELECT count(*) FROM payments WHERE invoice_id = :invoice',
            ['invoice' => $session['invoice_id']],
        );
        self::assertSame('2', (string) (is_scalar($attempts) ? $attempts : 'not counted'));
    }

    public function testTheSessionReportsAFailedPaymentAsSuch(): void
    {
        $session = $this->sessionOf($this->open());
        $id = $session['id'];
        $paymentId = $session['payment_id'];
        self::assertIsString($id);
        self::assertIsString($paymentId);

        $this->connection->executeStatement(
            // `payments_failed_has_reason` requires a `failure_code` on any
            // FAILED payment: a failure nobody can name is a dead end for
            // whoever has to explain it to the customer. The prose reason is
            // set alongside it because a code alone explains nothing.
            <<<'SQL'
                UPDATE payments
                   SET status = 'FAILED', failed_at = now(),
                       failure_code = 'card_declined', failure_reason = 'The card was declined.'
                 WHERE id = :id
                SQL,
            ['id' => $paymentId],
        );

        $read = $this->sessionOf($this->request('GET', '/api/v1/checkout/sessions/' . $id, $this->headers()));

        // Not AWAITING_PAYMENT: nothing is coming, and saying otherwise would
        // hide the state a retry exists for.
        self::assertSame('PAYMENT_FAILED', $read['status'] ?? null);
    }

    /**
     * A free offer that renews, bought through the door every offer is bought
     * through (ADR-078).
     *
     * It completed the order with a €0 invoice and then asked the provider to
     * authorize a payment of nothing against it. Nothing ever settled that
     * invoice, so once the grace ran out the collection pass put the free seat
     * in arrears — for owing zero.
     */
    public function testAFreeOfferThatRenewsCompletesWithNoDocumentAndNoPayment(): void
    {
        $this->saveProfile();
        $this->offer = $this->seedFreeTier();

        $response = $this->open();

        self::assertSame(201, $response->getStatusCode());

        $session = $this->sessionOf($response);

        self::assertSame('COMPLETED', $session['status'] ?? null);
        self::assertIsString($session['subscription_id'] ?? null);
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM payments'));
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM invoices'));
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT status FROM subscriptions'));
    }

    // --- What the buyer is told, and what happens to money nobody can use ----

    /**
     * A purchase was silent from end to end until ADR-079: the only mail this
     * platform sent about money was the one asking for it.
     */
    public function testThePurchaserIsToldTheSeatHasStarted(): void
    {
        $session = $this->sessionOf($this->open());

        // Nothing yet: an order awaiting its money has started nothing.
        self::assertSame(0, $this->noticesOf('purchase.confirmed'));

        $this->pay($session, 'evt_paid');

        // Raised on the payment's own transaction, to the person the seat is
        // for, and sent by the queue — never by the webhook that recorded it.
        self::assertSame(1, $this->noticesOf('purchase.confirmed'));
        self::assertSame(
            $this->user,
            $this->connection->fetchOne("SELECT recipient_user_id FROM notifications WHERE type = 'purchase.confirmed'"),
        );

        // A second delivery of the same event raises no second notice.
        $this->pay($session, 'evt_paid');
        self::assertSame(1, $this->noticesOf('purchase.confirmed'));
    }

    public function testThePurchaserIsToldTheCardWasRefusedAndWhereToTryAgain(): void
    {
        $session = $this->sessionOf($this->open());
        $reference = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments WHERE id = :id',
            ['id' => $session['payment_id'] ?? null],
        );
        self::assertIsString($reference);

        $body = $this->json(['id' => 'evt_refused', 'type' => 'payment.failed', 'payment_id' => $reference]);
        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => (new StubPaymentProvider(self::SECRET))->sign($body)],
            $body,
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(1, $this->noticesOf('purchase.payment_failed'));

        $payload = $this->connection->fetchOne(
            "SELECT payload::text FROM notifications WHERE type = 'purchase.payment_failed'",
        );
        self::assertIsString($payload);

        // The link is to the purchase itself, where a new attempt is made —
        // and nothing about the card, the provider or the amount leaves (§31).
        self::assertStringContainsString('/checkout/', $payload);
        self::assertStringNotContainsString('2900', $payload);
        self::assertStringNotContainsString('card', strtolower($payload));
    }

    /**
     * Somebody suspended for an unpaid invoice is not sold a second seat.
     *
     * The index counts a suspended seat as held, so the sale took their money
     * and could start nothing; the old seat stayed shut because the money had
     * gone to another invoice. The answer is the invoice they owe.
     */
    public function testSomebodyInArrearsIsSentToTheInvoiceTheyOweAndNotSoldAgain(): void
    {
        $session = $this->sessionOf($this->open());
        $this->pay($session, 'evt_first');

        $debt = $this->connection->fetchOne('SELECT id FROM invoices LIMIT 1');
        self::assertIsString($debt);
        $this->connection->executeStatement(
            "UPDATE subscriptions SET status = 'PAST_DUE', past_due_since = now(), past_due_invoice_id = :debt",
            ['debt' => $debt],
        );

        $response = $this->open();

        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('SEAT_IN_ARREARS', $error['code'] ?? null);
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM orders'));
    }

    /**
     * **A held order is not a dead end** (ADR-079). The money arrived for a
     * seat already held; the screen promised a refund and nothing made one.
     * Through `JobRunner::runOnce`, which is what cron calls.
     */
    public function testMoneyThatArrivedForASeatAlreadyHeldGoesBackWithItsCreditNote(): void
    {
        $first = $this->sessionOf($this->open());
        $second = $this->sessionOf($this->open());
        $this->pay($first, 'evt_first');
        $this->pay($second, 'evt_second');
        self::assertSame('HELD', $this->sessionOf($this->show($second))['status'] ?? null);

        $this->cron();

        $read = $this->sessionOf($this->show($second));

        // Cancelled, and the money given back — which is not what CANCELLED
        // says of a checkout nobody paid.
        self::assertSame('REFUNDED', $read['status'] ?? null);
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM refunds'));
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM credit_notes'));
        self::assertSame(1, $this->noticesOf('purchase.refunded'));

        // The seat that was live is untouched, and so is its order.
        self::assertSame('COMPLETED', $this->sessionOf($this->show($first))['status'] ?? null);
        self::assertEquals(1, $this->connection->fetchOne("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"));

        // A second pass finds nothing left to do.
        $this->cron();
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM refunds'));
        self::assertSame(1, $this->noticesOf('purchase.refunded'));
    }

    /**
     * **A checkout nobody is paying is given up** once the product's own
     * delay has passed: invoice cancelled, VAT reversed, order cancelled —
     * exactly what the buyer's own "cancel" does.
     */
    public function testAnAbandonedCheckoutIsGivenUpAfterTheProductsDelay(): void
    {
        $session = $this->sessionOf($this->open());
        $invoice = $session['invoice_id'] ?? null;
        self::assertIsString($invoice);

        // The first attempt died — a closed tab leaves it open at the
        // provider, which the next test is about.
        $this->connection->executeStatement(
            "UPDATE payments SET status = 'FAILED', failed_at = now(), failure_code = 'card_declined', failure_reason = 'Declined.'",
        );

        // Not yet: the default is two days.
        $this->ageTheOrders(47);
        $this->cron();
        self::assertSame('PAYMENT_FAILED', $this->sessionOf($this->show($session))['status'] ?? null);

        $this->ageTheOrders(49);
        $this->finishTheSchedule();
        $this->cron();

        self::assertSame('CANCELLED', $this->sessionOf($this->show($session))['status'] ?? null);
        self::assertSame('CANCELLED', $this->connection->fetchOne('SELECT status FROM invoices WHERE id = :id', ['id' => $invoice]));

        // What the invoice declared is reversed: nothing was sold.
        $vat = $this->vatOf($invoice);
        self::assertSame(0, $vat['base']);
        self::assertSame(0, $vat['vat']);
    }

    /**
     * A closed tab leaves its attempt open at the provider. Giving the
     * checkout up abandons it there first (ADR-076), so nothing can settle an
     * invoice that has just been cancelled.
     */
    public function testAnAttemptLeftOpenIsAbandonedAtTheProviderBeforeTheCheckoutIsGivenUp(): void
    {
        $session = $this->sessionOf($this->open());

        $this->ageTheOrders(500);
        $this->cron();

        self::assertSame('CANCELLED', $this->sessionOf($this->show($session))['status'] ?? null);
        self::assertSame('CANCELLED', $this->connection->fetchOne('SELECT status FROM payments'));
        self::assertSame('CANCELLED', $this->connection->fetchOne('SELECT status FROM invoices'));
    }

    /** A checkout whose money has arrived is never taken for abandoned. */
    public function testAPaidCheckoutIsNeverGivenUpHoweverOld(): void
    {
        $session = $this->sessionOf($this->open());
        $this->pay($session, 'evt_paid');
        $this->ageTheOrders(500);
        $this->cron();

        self::assertSame('COMPLETED', $this->sessionOf($this->show($session))['status'] ?? null);
        self::assertEquals(0, $this->connection->fetchOne("SELECT count(*) FROM invoices WHERE status = 'CANCELLED'"));
    }

    /**
     * The delay is the product's, read from its configuration and never a
     * constant: configure six hours and a seven-hour-old checkout goes, where
     * the default would have kept it two days.
     */
    public function testTheProductDecidesHowLongACheckoutMayWait(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'checkout', CAST('{"abandon_after_hours": 6}' AS jsonb))
                SQL,
            ['product' => $this->product],
        );

        $session = $this->sessionOf($this->open());
        $this->connection->executeStatement(
            "UPDATE payments SET status = 'FAILED', failed_at = now(), failure_code = 'card_declined', failure_reason = 'Declined.'",
        );
        $this->ageTheOrders(7);
        $this->cron();

        self::assertSame('CANCELLED', $this->sessionOf($this->show($session))['status'] ?? null);
    }

    /**
     * The provider unreachable, after the order and its numbered invoice were
     * committed: the buyer used to get an error with no session to come back
     * to, and a second click raised a second invoice for the same purchase.
     */
    public function testAProviderOutageStillLeavesASessionToComeBackTo(): void
    {
        $stub = new StubPaymentProvider(self::SECRET);
        $this->override([
            PaymentProviders::class => new PaymentProviders([
                new class ($stub) implements PaymentProvider {
                    public function __construct(private readonly PaymentProvider $inner)
                    {
                    }

                    public function name(): string
                    {
                        return $this->inner->name();
                    }

                    public function isSandbox(): bool
                    {
                        return $this->inner->isSandbox();
                    }

                    public function clientKey(): ?string
                    {
                        return $this->inner->clientKey();
                    }

                    public function authorize(Money $amount, string $reference, ?string $attemptKey = null): ProviderPayment
                    {
                        throw new RuntimeException('The provider did not answer.');
                    }

                    public function verify(string $rawBody, array $headers): void
                    {
                        $this->inner->verify($rawBody, $headers);
                    }

                    public function parse(string $rawBody): ?ProviderEvent
                    {
                        return $this->inner->parse($rawBody);
                    }

                    public function refund(Payment $payment, Money $amount, string $reason, int $attempt = 1): ProviderRefund
                    {
                        return $this->inner->refund($payment, $amount, $reason, $attempt);
                    }

                    public function cancel(Payment $payment): bool
                    {
                        return $this->inner->cancel($payment);
                    }
                },
            ]),
        ]);

        $response = $this->open();

        self::assertSame(201, $response->getStatusCode());

        $session = $this->sessionOf($response);

        // Awaiting, payable, and with no attempt: the next one starts from
        // its invoice.
        self::assertSame('AWAITING_PAYMENT', $session['status'] ?? null);
        self::assertIsString($session['invoice_id'] ?? null);
        self::assertArrayHasKey('payment_id', $session);
        self::assertNull($session['payment_id']);
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM invoices'));
    }

    // --- Helpers ------------------------------------------------------------

    private function noticesOf(string $type): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM notifications WHERE type = :type', ['type' => $type]);
        self::assertIsInt($count);

        return $count;
    }

    /** One pass of the queue, scheduled and claimed the way cron does it. */
    private function cron(): void
    {
        $runner = $this->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);

        $outcome = $runner->runOnce(100);
        self::assertSame(0, $outcome['failed']);
    }

    /** Lets the schedule queue its periodic work again within one test. */
    private function finishTheSchedule(): void
    {
        $this->connection->executeStatement("UPDATE jobs SET created_at = now() - interval '2 days'");
    }

    /** Orders last moved this many hours ago. */
    private function ageTheOrders(int $hours): void
    {
        $this->connection->executeStatement(
            'UPDATE orders SET updated_at = now() - make_interval(hours => :hours)',
            ['hours' => $hours],
        );
    }

    /** A plan that costs nothing and renews: a tier, not a free period. */
    private function seedFreeTier(): string
    {
        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'FREE', 'Free', 10) RETURNING id",
            ['product' => $this->product],
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'free', 'Atlas Free') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 0, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $offer],
        );

        return $offer;
    }

    private function open(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/checkout/sessions',
            $this->headers(),
            $this->json(['offer_id' => $this->offer]),
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function show(array $session): ResponseInterface
    {
        $id = $session['id'];
        self::assertIsString($id);

        return $this->request('GET', '/api/v1/checkout/sessions/' . $id, $this->headers());
    }

    /**
     * The provider saying the session's payment succeeded, signed as the
     * stub signs.
     *
     * @param array<string, mixed> $session
     */
    private function pay(array $session, string $eventId): ResponseInterface
    {
        $reference = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments WHERE id = :id',
            ['id' => $session['payment_id'] ?? null],
        );
        self::assertIsString($reference);

        $body = $this->json(['id' => $eventId, 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        return $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => (new StubPaymentProvider(self::SECRET))->sign($body)],
            $body,
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function cancel(array $session): ResponseInterface
    {
        $id = $session['id'];
        self::assertIsString($id);

        return $this->request('POST', '/api/v1/checkout/sessions/' . $id . '/cancel', $this->headers());
    }

    private function retry(string $paymentId): ResponseInterface
    {
        return $this->request('POST', '/api/v1/payments/' . $paymentId . '/retry', $this->headers());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionOf(ResponseInterface $response): array
    {
        $session = $this->decode($response)['session'] ?? null;

        self::assertIsArray($session);

        /** @var array<string, mixed> $session */
        return $session;
    }

    private function saveProfile(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json(['legal_name' => 'Acme SARL', 'country_code' => 'FR', 'city' => 'Paris']),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function configure(): void
    {
        $supplier = json_encode([
            'legal_name' => 'Atlas SAS',
            'vat_number' => 'FR12345678901',
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
    }

    private function seedCatalogue(): void
    {
        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $this->offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'pro', 'Atlas Pro') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $this->offer],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
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
}
