<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Payment\Infrastructure\Stripe\StripePaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\RecordingStripeHttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * The whole chain with the Stripe adapter registered (ADR-048): a payment
 * started through the API creates a PaymentIntent, and Stripe's own events —
 * real shapes, from `tests/Fixtures/stripe/`, signed the way Stripe signs —
 * settle the invoice, refund it and dispute it, exactly once each.
 *
 * `PaymentWebhookTest` proves the *pipeline* against the stub and stays as
 * it is. This proves the adapter fits it: same endpoint, same transaction,
 * same ledger, a different provider on the other end. Stripe itself is not
 * called — the SDK's transport is a recording fake — because everything
 * Stripe would answer is a fixture, and a test that needs a third party's
 * uptime fails on a Friday evening for reasons nobody can fix.
 */
#[CoversNothing]
final class StripeWebhookTest extends DatabaseApiTestCase
{
    private const WEBHOOK_SECRET = 'whsec_integration_0123456789';
    private const NOW = 1_789_200_000;

    private RecordingStripeHttpClient $stripe;
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $invoice = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new RecordingStripeHttpClient();
        ApiRequestor::setHttpClient($this->stripe);

        $this->product = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->user = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id");
        $this->invoice = $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units,
                     issued_at, supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, 'F-2026-0042', 'ISSUED', 'EUR', 4083, 817, 4900, now(),
                        CAST('{"legal_name":"Atlas SAS"}' AS jsonb),
                        CAST('{"legal_name":"Acme SARL"}' AS jsonb))
                RETURNING id
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoice_lines
                    (invoice_id, position, description, quantity, unit_price_minor_units,
                     discount_minor_units, net_minor_units, vat_rate_basis_points,
                     vat_minor_units, gross_minor_units)
                VALUES (:invoice, 1, 'Atlas Pro (v1) — subscription', 1, 4083, 0, 4083, 2000, 817, 4900)
                SQL,
            ['invoice' => $this->invoice],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            PaymentProviders::class => new PaymentProviders([
                new StripePaymentProvider(
                    new StripeClient(['api_key' => 'sk_test_integration']),
                    self::WEBHOOK_SECRET,
                    'pk_test_integration',
                    false,
                    static fn (): int => self::NOW,
                ),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'billing.pay', 'payments.read', 'payments.manage'],
                ),
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        $curl = CurlClient::instance();
        \assert($curl instanceof ClientInterface);
        ApiRequestor::setHttpClient($curl);
        parent::tearDown();
    }

    // --- Starting ------------------------------------------------------------

    public function testStartingAPaymentCreatesOneIntentForTheInvoicesAmountAndKeysOnIt(): void
    {
        $this->stripe->answer(['id' => 'pi_3Q2RxX0000000000000001', 'object' => 'payment_intent', 'client_secret' => 'pi_3Q2_secret_abc']);

        $response = $this->startPayment();

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('stripe', $body['provider'] ?? null);
        self::assertSame('pi_3Q2RxX0000000000000001', $body['provider_payment_id'] ?? null);
        // Handed out once, never stored.
        self::assertSame('pi_3Q2_secret_abc', $body['client_secret'] ?? null);
        self::assertStringNotContainsString('pi_3Q2_secret_abc', (string) json_encode($this->connection->fetchAllAssociative('SELECT * FROM payments')));

        // The invoice's gross, in cents, for the invoice's currency, referenced
        // by number and attempt.
        $sent = $this->stripe->requests[0]['params'];
        self::assertSame(4900, $sent['amount']);
        self::assertSame('eur', $sent['currency']);
        self::assertIsArray($sent['metadata']);
        self::assertSame('F-2026-0042/1', $sent['metadata']['reference'] ?? null);
    }

    // --- The happy chain, through Stripe's own events -----------------------

    public function testStripesSucceededEventSettlesThePaymentAndTheInvoice(): void
    {
        $this->started();

        $response = $this->deliver('payment_intent.succeeded');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('APPLIED', $this->decode($response)['outcome'] ?? null);
        self::assertSame('SUCCEEDED', $this->statusOfPayment());
        self::assertSame('PAID', $this->statusOfInvoice());
        // The intent does not say what paid; the charge will.
        self::assertNull($this->connection->fetchOne('SELECT method FROM payments'));
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
    }

    // Stripe sends `charge.succeeded` and `payment_intent.succeeded` for the
    // same money in no guaranteed order. Either way the row ends up SUCCEEDED
    // with its kind of instrument, the ledger has one PAYMENT_SUCCEEDED, and
    // the number never reached the database.

    public function testTheChargeArrivingFirstFillsInTheInstrumentAndMovesNothing(): void
    {
        $this->started();

        $charge = $this->deliver('charge.succeeded');
        self::assertSame(200, $charge->getStatusCode());
        self::assertSame('APPLIED', $this->decode($charge)['outcome'] ?? null);
        self::assertSame('PENDING', $this->statusOfPayment());
        self::assertSame('CARD', $this->connection->fetchOne('SELECT method FROM payments'));
        self::assertSame(['PAYMENT_INITIATED'], $this->ledger());

        self::assertSame(200, $this->deliver('payment_intent.succeeded')->getStatusCode());
        self::assertSame('SUCCEEDED', $this->statusOfPayment());
        self::assertSame('CARD', $this->connection->fetchOne('SELECT method FROM payments'));
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM payment_events'));
        self::assertSame(0, $this->rowsMatching("SELECT count(*) FROM payment_events WHERE payload::text LIKE '%4242%'"));
    }

    public function testTheChargeArrivingSecondFillsInTheInstrumentOfACollectedPayment(): void
    {
        $this->started();

        self::assertSame(200, $this->deliver('payment_intent.succeeded')->getStatusCode());
        self::assertSame(200, $this->deliver('charge.succeeded')->getStatusCode());
        self::assertSame('SUCCEEDED', $this->statusOfPayment());
        self::assertSame('CARD', $this->connection->fetchOne('SELECT method FROM payments'));
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
    }

    public function testTheChargeIsRecordedOnceLikeAnyDelivery(): void
    {
        $this->started();

        self::assertSame(200, $this->deliver('charge.succeeded')->getStatusCode());
        $again = $this->deliver('charge.succeeded');

        self::assertSame(202, $again->getStatusCode());
        self::assertSame('DUPLICATE', $this->decode($again)['outcome'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM payment_events'));
    }

    public function testTheSameDeliveryTwiceIsOneActivation(): void
    {
        $this->started();

        self::assertSame(200, $this->deliver('payment_intent.succeeded')->getStatusCode());
        $again = $this->deliver('payment_intent.succeeded');

        // 202: accounted for, and it changed nothing.
        self::assertSame(202, $again->getStatusCode());
        self::assertSame('DUPLICATE', $this->decode($again)['outcome'] ?? null);
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
    }

    public function testAFailureArrivingAfterSuccessIsStaleNotApplied(): void
    {
        $this->started();
        $this->deliver('payment_intent.succeeded');

        $late = $this->deliver('payment_intent.payment_failed');

        self::assertSame(202, $late->getStatusCode());
        self::assertSame('IGNORED_STALE', $this->decode($late)['outcome'] ?? null);
        self::assertSame('SUCCEEDED', $this->statusOfPayment());
    }

    public function testAFailedPaymentRecordsTheIssuersWordAndLeavesTheInvoiceOwed(): void
    {
        $this->started();

        $this->deliver('payment_intent.payment_failed');

        self::assertSame('FAILED', $this->statusOfPayment());
        self::assertSame('ISSUED', $this->statusOfInvoice());
        self::assertSame(
            ['insufficient_funds', 'Your card has insufficient funds.'],
            array_values($this->connection->fetchAssociative('SELECT failure_code, failure_reason FROM payments') ?: []),
        );
    }

    public function testARefundIsAskedOfStripeAndSettlesWhenStripeSaysSo(): void
    {
        $this->started();
        $this->deliver('payment_intent.succeeded');
        $this->stripe->answer(['id' => 're_3Q2RxX0000000000000001', 'object' => 'refund', 'status' => 'pending']);

        $asked = $this->refund(['amount_minor_units' => 1900, 'reason' => 'REQUESTED']);

        self::assertSame(202, $asked->getStatusCode());
        self::assertSame('PENDING', $this->decode($asked)['status'] ?? null);
        self::assertSame('pi_3Q2RxX0000000000000001', $this->stripe->requests[1]['params']['payment_intent'] ?? null);
        self::assertSame(1900, $this->stripe->requests[1]['params']['amount'] ?? null);

        // Stripe's own word, later: the refund keyed on the intent, named by re_….
        self::assertSame(202, $this->deliver('refund.updated.pending')->getStatusCode());
        self::assertSame('PENDING', $this->connection->fetchOne('SELECT status FROM refunds'));

        self::assertSame(200, $this->deliver('refund.updated.succeeded')->getStatusCode());
        self::assertSame('SUCCEEDED', $this->connection->fetchOne('SELECT status FROM refunds'));
        self::assertSame('PARTIALLY_REFUNDED', $this->statusOfPayment());
    }

    public function testADisputeIsAChargebackAndTheInvoiceIsNotRewritten(): void
    {
        $this->started();
        $this->deliver('payment_intent.succeeded');

        self::assertSame(200, $this->deliver('charge.dispute.created')->getStatusCode());

        self::assertSame('CHARGEBACK', $this->statusOfPayment());
        self::assertSame('PAID', $this->statusOfInvoice());
    }

    public function testAnEventThisPlatformDoesNotModelIsAcceptedAndChangesNothing(): void
    {
        $this->started();

        $response = $this->deliver('payment_intent.created');

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_NOT_APPLICABLE', $this->decode($response)['outcome'] ?? null);
        self::assertSame('PENDING', $this->statusOfPayment());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM payment_events'));
    }

    // --- Authenticity ----------------------------------------------------------

    public function testAnUnsignedDeliveryIsRefusedAndNothingIsWritten(): void
    {
        $this->started();

        $response = $this->request('POST', '/api/v1/webhooks/payments/stripe', [], self::fixture('payment_intent.succeeded'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('PENDING', $this->statusOfPayment());
        self::assertFalse($this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM payment_events)'));
    }

    public function testADeliverySignedWithAnotherSecretIsRefused(): void
    {
        $this->started();
        $body = self::fixture('payment_intent.succeeded');

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            ['Stripe-Signature' => 't=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.' . $body, 'whsec_somebody_elses')],
            $body,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('PENDING', $this->statusOfPayment());
    }

    public function testAReplayFromOutsideTheWindowIsRefused(): void
    {
        $this->started();
        $body = self::fixture('payment_intent.succeeded');
        $then = self::NOW - 3600;

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            ['Stripe-Signature' => 't=' . $then . ',v1=' . hash_hmac('sha256', $then . '.' . $body, self::WEBHOOK_SECRET)],
            $body,
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testALiveEventOnASandboxKeyIsNotApplied(): void
    {
        $this->started();

        $response = $this->deliver('payment_intent.succeeded.livemode');

        // Accepted — Stripe must not retry it — and applied to nothing.
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('PENDING', $this->statusOfPayment());
    }

    // --- Helpers --------------------------------------------------------------

    private function startPayment(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->invoice . '/payments',
            ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'],
        );
    }

    /** A started payment whose intent is the one every fixture names. */
    private function started(): void
    {
        $this->stripe->answer(['id' => 'pi_3Q2RxX0000000000000001', 'object' => 'payment_intent', 'client_secret' => 'pi_3Q2_secret_abc']);

        self::assertSame(201, $this->startPayment()->getStatusCode());
    }

    private function deliver(string $fixture): ResponseInterface
    {
        $body = self::fixture($fixture);

        return $this->request(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            ['Stripe-Signature' => 't=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.' . $body, self::WEBHOOK_SECRET)],
            $body,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refund(array $body): ResponseInterface
    {
        $paymentId = $this->connection->fetchOne('SELECT id FROM payments');
        self::assertIsString($paymentId);

        return $this->request(
            'POST',
            '/api/v1/billing/payments/' . $paymentId . '/refund',
            ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'],
            $this->json($body),
        );
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsInt($count);

        return $count;
    }

    private function statusOfPayment(): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM payments');
        self::assertIsString($status);

        return $status;
    }

    private function statusOfInvoice(): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM invoices WHERE id = :id', ['id' => $this->invoice]);
        self::assertIsString($status);

        return $status;
    }

    /**
     * @return list<string>
     */
    private function ledger(): array
    {
        $kinds = $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM financial_events ORDER BY type');

        return array_values(array_filter($kinds, is_string(...)));
    }

    private static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/stripe/' . $name . '.json');
        self::assertIsString($body);

        return $body;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($identifier);

        return $identifier;
    }
}
