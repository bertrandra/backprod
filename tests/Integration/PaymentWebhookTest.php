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
 * §37.4's billing scenarios, through the real pipeline and the real database:
 * the happy chain, payment failed, webhook duplicated, webhook delayed,
 * refund, chargeback.
 *
 * A double would defeat the point of the one that matters most. "Exactly one
 * activation" is a property of a unique index inside a transaction, and an
 * in-memory repository would implement it correctly by accident — proving
 * only that my two versions agree.
 */
#[CoversNothing]
final class PaymentWebhookTest extends DatabaseApiTestCase
{
    private const SECRET = 'test-signing-secret';

    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $invoice = '';

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

        $this->invoice = $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units,
                     issued_at, supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, '2026-000001', 'ISSUED', 'EUR', 2900, 580, 3480, now(),
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
                VALUES (:invoice, 1, 'Atlas Pro (v1) — subscription', 1, 2900, 0, 2900, 2000, 580, 3480)
                SQL,
            ['invoice' => $this->invoice],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            // A known secret, so the test can produce the signatures a real
            // provider would.
            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'payments.read', 'payments.manage'],
                ),
            ]),
        ]);
    }

    // --- The happy chain -----------------------------------------------------

    public function testStartingAPaymentTakesTheInvoicesAmountAndNoInstrument(): void
    {
        $response = $this->startPayment();

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('PENDING', $body['status'] ?? null);
        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $body['amount'] ?? null);
        self::assertIsString($body['client_secret'] ?? null);

        // The secret is handed back and never stored: it is short-lived and
        // a credential of sorts (§31).
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM payments WHERE provider_payment_id LIKE 'stub_secret%'",
        ));
    }

    public function testASucceededWebhookSettlesThePaymentAndTheInvoice(): void
    {
        $reference = $this->startedReference();

        $response = $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('APPLIED', $this->decode($response)['outcome'] ?? null);

        self::assertSame('SUCCEEDED', $this->statusOfPayment($reference));
        // The invoice moved in the same transaction: a collected payment and
        // an invoice still saying it is owed must never be observable.
        self::assertSame('PAID', $this->statusOfInvoice());
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
    }

    // --- The exit criterion --------------------------------------------------

    /**
     * The milestone's exit criterion: replaying a webhook twice produces
     * exactly one activation.
     *
     * It is deliberately asserted on the ledger rather than only on the
     * statuses. Setting a status twice is harmless; appending to a financial
     * ledger twice is revenue counted twice, which is the failure webhook
     * replay actually causes in production.
     */
    public function testReplayingAWebhookProducesExactlyOneActivation(): void
    {
        $reference = $this->startedReference();
        $delivery = ['id' => 'evt_once', 'type' => 'payment.succeeded', 'payment_id' => $reference];

        $first = $this->deliver($delivery);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('APPLIED', $this->decode($first)['outcome'] ?? null);

        $paidAt = $this->connection->fetchOne('SELECT paid_at FROM invoices WHERE id = :id', ['id' => $this->invoice]);

        $second = $this->deliver($delivery);

        // 2xx, not an error: a provider retries anything else, and answering
        // an already-handled delivery with a 500 is how a retry storm starts.
        self::assertSame(202, $second->getStatusCode());
        self::assertSame('DUPLICATE', $this->decode($second)['outcome'] ?? null);
        self::assertFalse($this->decode($second)['applied'] ?? null);

        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM payment_events'));
        self::assertSame(['INVOICE_PAID', 'PAYMENT_INITIATED', 'PAYMENT_SUCCEEDED'], $this->ledger());
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'PAYMENT_SUCCEEDED'",
        ));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'INVOICE_PAID'",
        ));
        self::assertSame(
            $paidAt,
            $this->connection->fetchOne('SELECT paid_at FROM invoices WHERE id = :id', ['id' => $this->invoice]),
        );
    }

    public function testTwoDifferentDeliveriesAboutTheSamePaymentAreNotConfused(): void
    {
        $reference = $this->startedReference();

        $this->deliver(['id' => 'evt_a', 'type' => 'payment.authorized', 'payment_id' => $reference]);
        $this->deliver(['id' => 'evt_b', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        // Distinct event ids, so both are recorded and both applied. Replay
        // protection is about identity, not about the type of the event.
        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM payment_events'));
        self::assertSame('SUCCEEDED', $this->statusOfPayment($reference));
    }

    // --- Payment failed ------------------------------------------------------

    public function testAFailedPaymentLeavesTheInvoiceOwed(): void
    {
        $reference = $this->startedReference();

        $response = $this->deliver([
            'id' => 'evt_fail',
            'type' => 'payment.failed',
            'payment_id' => $reference,
            'failure_code' => 'card_declined',
            'failure_reason' => 'The card was declined.',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FAILED', $this->statusOfPayment($reference));
        // The debt is still owed, which is the whole point of not letting the
        // browser that redirected back decide.
        self::assertSame('ISSUED', $this->statusOfInvoice());
    }

    public function testAFailureWithNoReasonStillRecords(): void
    {
        $reference = $this->startedReference();

        // The schema insists a failed payment carries a reason. An adapter
        // that gave none must not turn that into a 500 the provider then
        // retries forever.
        $response = $this->deliver(['id' => 'evt_bare', 'type' => 'payment.failed', 'payment_id' => $reference]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FAILED', $this->statusOfPayment($reference));
    }

    // --- Webhook delayed -----------------------------------------------------

    public function testAFailureArrivingAfterSuccessDoesNotReverseIt(): void
    {
        $reference = $this->startedReference();

        $this->deliver(['id' => 'evt_win', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        // The retry of an earlier failure, landing late. Applying it would
        // reverse a collected payment on the strength of a stale message.
        $late = $this->deliver([
            'id' => 'evt_late',
            'type' => 'payment.failed',
            'payment_id' => $reference,
            'failure_code' => 'card_declined',
        ]);

        self::assertSame(202, $late->getStatusCode());
        self::assertSame('IGNORED_STALE', $this->decode($late)['outcome'] ?? null);
        self::assertSame('SUCCEEDED', $this->statusOfPayment($reference));
        self::assertSame('PAID', $this->statusOfInvoice());

        // Recorded rather than discarded: an operator investigating needs to
        // see that it arrived and why nothing happened.
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM payment_events WHERE outcome = 'IGNORED_STALE'",
        ));
    }

    public function testADeliveryForAPaymentNobodyKnowsIsRecordedNotDiscarded(): void
    {
        $response = $this->deliver([
            'id' => 'evt_orphan',
            'type' => 'payment.succeeded',
            'payment_id' => 'stub_pi_never_seen',
        ]);

        // It can happen legitimately: the provider redirected the customer
        // faster than our own request finished writing the row.
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_UNKNOWN_PAYMENT', $this->decode($response)['outcome'] ?? null);
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM payment_events WHERE outcome = 'IGNORED_UNKNOWN_PAYMENT'",
        ));
    }

    // --- Authentication of the webhook itself --------------------------------

    public function testTheWebhookNeedsNoCredentialButDoesNeedASignature(): void
    {
        $body = $this->json(['id' => 'evt_x', 'type' => 'payment.succeeded', 'payment_id' => 'stub_pi_1']);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => $this->sign($body)],
            $body,
        );

        // No Authorization header anywhere: the sender is a provider, not a
        // person. What authenticates it is the signature.
        self::assertNotSame(401, $response->getStatusCode());
    }

    public function testAnUnsignedDeliveryIsRefused(): void
    {
        $body = $this->json(['id' => 'evt_x', 'type' => 'payment.succeeded', 'payment_id' => 'stub_pi_1']);

        $response = $this->request('POST', '/api/v1/webhooks/payments/stub', [], $body);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM payment_events'));
    }

    public function testATamperedBodyIsRefused(): void
    {
        $reference = $this->startedReference();
        $genuine = $this->json(['id' => 'evt_x', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        // A genuine delivery with the payment swapped, signature kept.
        $tampered = $this->json([
            'id' => 'evt_x',
            'type' => 'payment.succeeded',
            'payment_id' => 'stub_pi_somebody_elses',
        ]);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => $this->sign($genuine)],
            $tampered,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('PENDING', $this->statusOfPayment($reference));
    }

    public function testAnUnknownProviderIsNotFound(): void
    {
        $body = $this->json(['id' => 'evt_x', 'type' => 'payment.succeeded', 'payment_id' => 'stub_pi_1']);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/definitely-not-configured',
            [StubPaymentProvider::SIGNATURE_HEADER => $this->sign($body)],
            $body,
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testADeliveryThisPlatformDoesNotModelIsAccepted(): void
    {
        $body = $this->json(['id' => 'evt_odd', 'type' => 'invoice.dreamed', 'payment_id' => 'stub_pi_1']);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => $this->sign($body)],
            $body,
        );

        // 2xx, so the provider stops. A 4xx here would be retried until it
        // gave up, burying the deliveries that matter.
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_NOT_APPLICABLE', $this->decode($response)['outcome'] ?? null);
    }

    // --- Refund and chargeback -----------------------------------------------

    public function testARefundIsAskedForAndOnlySettlesWhenTheProviderSaysSo(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $asked = $this->refund();

        // 202: asking is not returning.
        self::assertSame(202, $asked->getStatusCode());
        self::assertSame('PENDING', $this->decode($asked)['status'] ?? null);
        self::assertSame('SUCCEEDED', $this->statusOfPayment($reference));

        $refundReference = $this->connection->fetchOne('SELECT provider_refund_id FROM refunds');
        self::assertIsString($refundReference);

        $this->deliver([
            'id' => 'evt_refunded',
            'type' => 'refund.succeeded',
            'payment_id' => $reference,
            'refund_id' => $refundReference,
        ]);

        self::assertSame('REFUNDED', $this->statusOfPayment($reference));

        $refundStatus = $this->connection->fetchOne('SELECT status FROM refunds');
        self::assertSame('SUCCEEDED', $refundStatus);
    }

    public function testAPartialRefundLeavesThePaymentPartlySettled(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $this->refund(['amount_minor_units' => 1000]);

        $refundReference = $this->connection->fetchOne('SELECT provider_refund_id FROM refunds');
        $this->deliver([
            'id' => 'evt_part',
            'type' => 'refund.succeeded',
            'payment_id' => $reference,
            'refund_id' => is_string($refundReference) ? $refundReference : '',
        ]);

        // Some of it is still ours, so the invoice it settled is still
        // settled.
        self::assertSame('PARTIALLY_REFUNDED', $this->statusOfPayment($reference));
        self::assertSame('PAID', $this->statusOfInvoice());
    }

    public function testRefundingMoreThanWasCollectedIsRefused(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $response = $this->refund(['amount_minor_units' => 9999]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('REFUND_EXCEEDS_PAYMENT', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnUncollectedPaymentCannotBeRefunded(): void
    {
        $this->startedReference();

        $response = $this->refund();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PAYMENT_NOT_REFUNDABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testAChargebackIsRecordedWithoutRewritingTheInvoice(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $response = $this->deliver([
            'id' => 'evt_cb',
            'type' => 'chargeback.opened',
            'payment_id' => $reference,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('CHARGEBACK', $this->statusOfPayment($reference));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'PAYMENT_CHARGEBACK'",
        ));

        // Deliberately still PAID. A bank dispute does not silently rewrite
        // the legal state of a document; correcting it is a credit note,
        // which is a decision somebody makes.
        self::assertSame('PAID', $this->statusOfInvoice());
    }

    public function testAClientCannotDeclareAChargebackItself(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $response = $this->refund(['reason' => 'CHARGEBACK']);

        // A chargeback is imposed by the customer's bank and arrives as a
        // webhook. Letting a caller declare one would let this platform
        // record a dispute that never happened.
        self::assertSame(400, $response->getStatusCode());
    }

    // --- Credit notes --------------------------------------------------------

    public function testCreditingAnInvoiceIssuesADocumentInItsOwnSeries(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->invoice . '/credit',
            $this->headers(),
            $this->json(['reason' => 'Billed the wrong entity']),
        );

        self::assertSame(201, $response->getStatusCode());

        $note = $this->decode($response);
        self::assertSame('AV' . date('Y') . '-000001', $note['number'] ?? null);
        self::assertSame('CREDIT', $note['direction'] ?? null);
        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $note['gross'] ?? null);

        // The lines are copied from the invoice's, which are themselves the
        // snapshot taken when it was issued.
        $lines = $note['lines'] ?? null;
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $line = $lines[0] ?? null;
        self::assertIsArray($line);
        self::assertSame('Atlas Pro (v1) — subscription', $line['description'] ?? null);

        // And the invoice moved, in the same transaction.
        self::assertSame('CREDITED', $this->statusOfInvoice());
    }

    public function testAnInvoiceCannotBeCreditedTwice(): void
    {
        $this->credit();
        $response = $this->credit();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('INVALID_INVOICE_TRANSITION', $this->errorOf($response)['code'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    public function testAPaidInvoiceCanStillBeCredited(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        // Money moved and then had to be given back. Cancelling is refused
        // for a paid invoice; crediting is the instrument that exists.
        self::assertSame(201, $this->credit()->getStatusCode());
        self::assertSame('CREDITED', $this->statusOfInvoice());
    }

    public function testCreditNotesAreListedForTheTenant(): void
    {
        $this->credit();

        $page = $this->decode($this->request('GET', '/api/v1/billing/credit-notes', $this->headers()));

        self::assertSame(1, $page['total'] ?? null);
    }

    // --- Permissions ---------------------------------------------------------

    public function testStartingAPaymentRequiresThePermission(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['payments.read']),
            ]),
        ]);

        $response = $this->startPayment();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM payments'));
    }

    public function testAnotherTenantsPaymentIsNotFound(): void
    {
        $this->startedReference();
        $paymentId = $this->connection->fetchOne('SELECT id FROM payments');
        self::assertIsString($paymentId);

        $other = $this->id("INSERT INTO tenants (name, slug) VALUES ('Rival', 'rival') RETURNING id");

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($other, $this->user, $this->product, ['TENANT_ADMIN'], ['payments.read']),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/billing/payments/' . $paymentId, $this->headers());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PAYMENT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    private function startPayment(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->invoice . '/payments',
            $this->headers(),
        );
    }

    /**
     * Starts a payment and returns the provider handle a webhook would carry.
     */
    private function startedReference(): string
    {
        self::assertSame(201, $this->startPayment()->getStatusCode());

        $reference = $this->connection->fetchOne('SELECT provider_payment_id FROM payments');
        self::assertIsString($reference);

        return $reference;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function deliver(array $event): ResponseInterface
    {
        $body = $this->json($event);

        return $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => $this->sign($body)],
            $body,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refund(array $body = []): ResponseInterface
    {
        $paymentId = $this->connection->fetchOne('SELECT id FROM payments');
        self::assertIsString($paymentId);

        return $this->request(
            'POST',
            '/api/v1/billing/payments/' . $paymentId . '/refund',
            $this->headers(),
            // No body rather than an empty one: json([]) encodes to "[]",
            // a JSON *array*, which the pipeline rightly refuses.
            $body === [] ? null : $this->json($body),
        );
    }

    private function credit(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->invoice . '/credit',
            $this->headers(),
            $this->json(['reason' => 'Correcting']),
        );
    }

    private function sign(string $body): string
    {
        return (new StubPaymentProvider(self::SECRET))->sign($body);
    }

    private function statusOfPayment(string $reference): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM payments WHERE provider_payment_id = :reference',
            ['reference' => $reference],
        );

        self::assertIsString($status);

        return $status;
    }

    private function statusOfInvoice(): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM invoices WHERE id = :id',
            ['id' => $this->invoice],
        );

        self::assertIsString($status);

        return $status;
    }

    /**
     * @return list<string>
     */
    private function ledger(): array
    {
        $types = $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM financial_events ORDER BY type');

        return array_map(static fn (mixed $type): string => is_string($type) ? $type : '', $types);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        self::assertIsNumeric($count);

        return (int) $count;
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
