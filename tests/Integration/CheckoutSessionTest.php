<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

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
        $this->connection->executeStatement(
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

    public function testASecondCheckoutIsRefusedWhileTheSubscriptionIsLive(): void
    {
        $first = $this->sessionOf($this->open());
        $this->pay($first, 'evt_first');
        self::assertSame('COMPLETED', $this->sessionOf($this->show($first))['status'] ?? null);

        $response = $this->open();

        // 409, before any document: the operator's own deployment showed
        // what the alternative costs — a numbered invoice, a charged card,
        // and a webhook that can never be honoured.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SUBSCRIPTION_ALREADY_ACTIVE', $this->errorOf($response)['code'] ?? null);

        $orders = $this->connection->fetchOne('SELECT count(*) FROM orders');
        self::assertSame('1', (string) (is_scalar($orders) ? $orders : 'not counted'));
        $invoices = $this->connection->fetchOne('SELECT count(*) FROM invoices');
        self::assertSame('1', (string) (is_scalar($invoices) ? $invoices : 'not counted'));
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

    // --- Helpers ------------------------------------------------------------

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
