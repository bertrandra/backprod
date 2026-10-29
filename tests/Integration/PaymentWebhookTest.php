<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\InvoiceRepository;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Database\Row;
use App\Shared\Exceptions\ConflictException;
use App\Tax\Service\VatReporting;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\RecordingPaymentProvider;
use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
                    ['billing.read', 'billing.manage', 'billing.pay', 'payments.read', 'payments.manage'],
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

    /**
     * A failed attempt on an invoice somebody has since paid says so.
     *
     * The commonest sequence there is: a card is declined, the customer tries
     * another, the second goes through. The first attempt stays `FAILED` for
     * ever because it is the record of what happened (ADR-034) — and the
     * payments screen showed a red refusal against a bill already settled, with
     * nothing on the row to say there was nothing to do.
     *
     * `invoice_settled` is the **invoice's** status, read beside the payment. A
     * screen concluding it from a sibling attempt would answer a question the
     * document answers, and would be wrong the moment a credit note moved it.
     */
    public function testAFailedAttemptSaysWhenTheInvoiceHasSinceBeenPaid(): void
    {
        $failed = $this->startedReference();

        self::assertSame(200, $this->deliver([
            'id' => 'evt_declined',
            'type' => 'payment.failed',
            'payment_id' => $failed,
            'failure_code' => 'card_declined',
            'failure_reason' => 'The card was declined.',
        ])->getStatusCode());

        // While the bill is owed, nothing softens the refusal: there *is*
        // something to do.
        self::assertFalse($this->listedPayment($failed)['invoice_settled'] ?? null);

        // A second attempt, which works. `startedReference()` cannot be used
        // twice — it reads `SELECT provider_payment_id FROM payments` with no
        // order, so with two rows it answers whichever PostgreSQL hands back
        // first, and delivering a success for the already-failed one is
        // deferred rather than applied.
        self::assertSame(201, $this->startPayment()->getStatusCode());

        $paid = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments ORDER BY created_at DESC, id DESC LIMIT 1',
        );
        self::assertIsString($paid);

        self::assertSame(200, $this->deliver([
            'id' => 'evt_second',
            'type' => 'payment.succeeded',
            'payment_id' => $paid,
        ])->getStatusCode());

        self::assertSame('PAID', $this->statusOfInvoice());

        // The failed attempt is still failed — and now says the bill is not.
        $row = $this->listedPayment($failed);
        self::assertSame('FAILED', $row['status'] ?? null);
        self::assertTrue($row['invoice_settled'] ?? null);
        self::assertSame('card_declined', $row['failure_code'] ?? null, 'the refusal is still on the record');
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

    // --- A refund carries its credit note (2026-09-26) ------------------------

    /**
     * The defect this section exists for. `Payments::refund` gave the money
     * back and wrote no document at all, so the invoice's fiscal fact stayed
     * declared while the money had gone — and a period close would have
     * frozen it.
     */
    public function testARefundInFullCreditsTheInvoiceAndReversesItsVat(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        self::assertSame(202, $this->refund()->getStatusCode());

        $note = $this->newestCreditNote();
        self::assertSame(3_480, Row::integer($note, 'gross_minor_units'));
        self::assertSame(2_900, Row::integer($note, 'net_minor_units'));
        self::assertSame(580, Row::integer($note, 'vat_minor_units'));

        // In full, so the document is undone and says so.
        self::assertSame('CREDITED', $this->statusOfInvoice());

        $reversal = $this->reversalOf(Row::string($note, 'id'));
        self::assertSame(-2_900, Row::integer($reversal, 'taxable_base'));
        self::assertSame(-580, Row::integer($reversal, 'vat_amount'));
        self::assertSame(2_000, Row::integer($reversal, 'vat_rate'));
        self::assertSame('STANDARD', Row::string($reversal, 'vat_regime'));
        self::assertSame('domestic.standard', Row::string($reversal, 'rule_id'));

        // Which is the point: nothing is declared on a sale whose money went
        // back.
        self::assertSame(0, $this->rowsMatching('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
    }

    public function testAPartialRefundCreditsItsShareAndLeavesTheRestStanding(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        self::assertSame(202, $this->refund(['amount_minor_units' => 1_160])->getStatusCode());

        // 11.60 back at 20% is 9.67 + 1.93. The base absorbs the rounding
        // and the VAT is the remainder, so the document totals exactly the
        // money that moved.
        $note = $this->newestCreditNote();
        self::assertSame(1_160, Row::integer($note, 'gross_minor_units'));
        self::assertSame(967, Row::integer($note, 'net_minor_units'));
        self::assertSame(193, Row::integer($note, 'vat_minor_units'));

        // Most of the invoice was still owed and was still paid. Marking it
        // CREDITED would say the rest was never owed.
        self::assertSame('PAID', $this->statusOfInvoice());

        $reversal = $this->reversalOf(Row::string($note, 'id'));
        self::assertSame(-967, Row::integer($reversal, 'taxable_base'));
        self::assertSame(-193, Row::integer($reversal, 'vat_amount'));

        // 580 charged, 193 given back.
        self::assertSame(387, $this->rowsMatching('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
    }

    public function testTheLastPartialRefundClosesTheInvoice(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        $this->refund(['amount_minor_units' => 1_160]);
        self::assertSame(202, $this->refund(['amount_minor_units' => 2_320])->getStatusCode());

        // Nothing of it is left uncredited, so it is undone — although
        // neither document credited the whole of it.
        self::assertSame('CREDITED', $this->statusOfInvoice());
        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(3_480, $this->rowsMatching(
            'SELECT coalesce(sum(gross_minor_units), 0) FROM credit_notes',
        ));
    }

    public function testAnInvoiceCarryingTwoRatesRefusesToBeCreditedInPart(): void
    {
        $this->addASecondRate();
        $this->collect();

        $response = $this->refund(['amount_minor_units' => 1_000]);

        // Declined rather than apportioned by guess, which is the rule
        // `CreditNotes` has always held.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CREDIT_NOTE_MULTIPLE_RATES', $this->errorOf($response)['code'] ?? null);

        // And the money did not move: the document is planned before the
        // provider is asked, precisely so a refusal costs nothing.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    public function testAnInvoiceCarryingTwoRatesIsStillRefundableInFull(): void
    {
        $this->addASecondRate();
        $this->collect();

        // Crediting in full needs no apportionment: each fact is negated as
        // it stands.
        self::assertSame(202, $this->refund()->getStatusCode());
        self::assertSame('CREDITED', $this->statusOfInvoice());
        self::assertSame(2, $this->rowsMatching(
            'SELECT count(*) FROM vat_transactions WHERE credit_note_id IS NOT NULL',
        ));
        self::assertSame(0, $this->rowsMatching('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
    }

    public function testTheReversalUsesTheRateTheInvoiceRecordedAndNotTodays(): void
    {
        // An invoice raised at 10%, which is nobody's standard rate today.
        // Recomputing from the customer's country now would credit 20% of a
        // different base and leave the difference declared for ever (§25.3).
        $this->reRateTheInvoice(1_000);
        $this->seedFiscalFact(2_900, 290, 1_000);
        $this->collect();

        self::assertSame(202, $this->refund(['amount_minor_units' => 1_100])->getStatusCode());

        $note = $this->newestCreditNote();
        self::assertSame(1_000, Row::integer($note, 'net_minor_units'));
        self::assertSame(100, Row::integer($note, 'vat_minor_units'));

        $reversal = $this->reversalOf(Row::string($note, 'id'));
        self::assertSame(1_000, Row::integer($reversal, 'vat_rate'));
        self::assertSame(-1_000, Row::integer($reversal, 'taxable_base'));
        self::assertSame(-100, Row::integer($reversal, 'vat_amount'));
    }

    public function testACreditedInvoiceCannotAlsoBeRefunded(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        self::assertSame(201, $this->credit()->getStatusCode());

        $response = $this->refund();

        // The fiscal fact is already reversed. Refunding on top of it would
        // reverse the same VAT twice and declare a negative sale that never
        // happened — so the refund is refused, and refused before the
        // provider is asked.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('INVALID_INVOICE_TRANSITION', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    public function testAPartlyCreditedInvoiceCannotThenBeCreditedInFull(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        $this->refund(['amount_minor_units' => 1_160]);

        // The invoice is still PAID, so the state machine has nothing to say
        // — and the arithmetic does: 11.60 is already back, and a full
        // credit on top would reverse 34.80 more than was ever charged.
        $response = $this->credit();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CREDIT_EXCEEDS_INVOICE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * The ceiling holds where the credit note is written, under the
     * numbering lock (2026-10-26): the plan's check is read before the money
     * moves, and two partial refunds at once both passed it. Raced here by
     * going around it.
     */
    public function testTheCreditCeilingHoldsWhereTheDocumentIsWritten(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        $this->refund(['amount_minor_units' => 1_160]);

        $invoices = $this->container()->get(InvoiceRepository::class);
        $creditNotes = $this->container()->get(CreditNoteRepository::class);
        self::assertInstanceOf(InvoiceRepository::class, $invoices);
        self::assertInstanceOf(CreditNoteRepository::class, $creditNotes);

        $invoice = $invoices->find($this->tenant, $this->product, $this->invoice);
        self::assertNotNull($invoice);

        try {
            $creditNotes->issue($invoice, $invoice->lines, $invoice->supplier, $invoice->customer, 'raced', null);
            self::fail('A credit past what the invoice charged was written.');
        } catch (ConflictException $refused) {
            self::assertSame('CREDIT_EXCEEDS_INVOICE', $refused->errorCode());
        }

        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * The money and its document are one write or neither.
     *
     * The lever is contrived — an invoice stripped of its lines has no
     * credit note to copy, so writing the document fails halfway — and it is
     * the only lever this suite has on the state the shared transaction
     * exists to make unreachable: a refund recorded with no document behind
     * it.
     */
    public function testARefundAndItsCreditNoteAreOneWriteOrNeither(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        TestDatabase::forge(
            $this->connection,
            ['invoice_lines'],
            'DELETE FROM invoice_lines WHERE invoice_id = :id',
            ['id' => $this->invoice],
        );

        self::assertSame(500, $this->refund()->getStatusCode());

        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'PAYMENT_REFUNDED'",
        ));
    }

    public function testACorrectionLandsInTheOpenPeriodAndNotTheClosedOne(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        // The quarter the sale belongs to, ended and filed.
        $period = $this->id(
            'INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)'
            . " VALUES ('FR', 'MONTHLY', current_date - 5, current_date - 1) RETURNING id",
        );
        TestDatabase::forge(
            $this->connection,
            ['vat_transactions'],
            'UPDATE vat_transactions SET transaction_date = current_date - 3',
        );

        $reporting = $this->container()->get(VatReporting::class);
        self::assertInstanceOf(VatReporting::class, $reporting);
        // The platform's own return: the period names no issuer.
        $declared = $reporting->close(null, $period, null);
        self::assertSame(580, $declared->totalVat);

        self::assertSame(202, $this->refund()->getStatusCode());

        // Dated the day of the correction. A correction belongs to the
        // period it is made in; dating it at the invoice would put it inside
        // a filing that is immutable.
        self::assertSame(1, $this->rowsMatching(
            'SELECT count(*) FROM vat_transactions'
            . ' WHERE credit_note_id IS NOT NULL AND transaction_date::date = current_date',
        ));
        self::assertSame(0, $this->rowsMatching(
            'SELECT count(*) FROM vat_transactions'
            . ' WHERE credit_note_id IS NOT NULL AND transaction_date::date <= current_date - 1',
        ));

        // And the figure that was filed has not moved.
        self::assertSame(580, $this->rowsMatching(
            'SELECT total_vat FROM vat_declarations WHERE period_id = ' . $this->connection->quote($period),
        ));
    }

    public function testTheCreditNoteIsNumberedInTheSeriesOfTheInvoiceItCorrects(): void
    {
        // The platform's series already has a correction in it.
        $theirs = $this->anotherInvoice(null);
        self::assertSame(201, $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $theirs . '/credit',
            $this->headers(),
            $this->json(['reason' => 'Not ours']),
        )->getStatusCode());

        // And this one is the organisation's own document (ADR-054).
        TestDatabase::forge(
            $this->connection,
            ['invoices'],
            'UPDATE invoices SET issuer_tenant_id = :tenant WHERE id = :id',
            ['tenant' => $this->tenant, 'id' => $this->invoice],
        );

        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        self::assertSame(202, $this->refund()->getStatusCode());

        $note = $this->newestCreditNote();

        // Its own first number, not the platform's second: a fresh decision
        // here would put Acme's correction in the middle of everybody's
        // sequence.
        self::assertSame('AV' . date('Y') . '-000001', Row::string($note, 'number'));
        self::assertSame($this->tenant, Row::nullableString($note, 'issuer_tenant_id'));

        // The fiscal fact is filed in the same return the original was, and
        // the platform's report reads `issuer_tenant_id IS NULL`.
        $reversal = $this->reversalOf(Row::string($note, 'id'));
        self::assertSame($this->tenant, Row::nullableString($reversal, 'issuer_tenant_id'));
    }

    // --- One live attempt per invoice (2026-10-29) ----------------------------

    /**
     * Every start minted a new attempt beside whatever was still live, so two
     * tabs could authorize one invoice twice. The live one is abandoned at the
     * provider first, and only then replaced — refusing outright would leave
     * an invoice whose page was closed unpayable for ever.
     */
    public function testANewAttemptAbandonsTheOneStillLive(): void
    {
        $provider = $this->recordingProvider();
        $first = $this->startedReference();

        self::assertSame(201, $this->startPayment()->getStatusCode());

        // The provider was asked to abandon the first before the second began.
        self::assertSame(['authorize', 'cancel', 'authorize'], $provider->asked);

        self::assertSame('CANCELLED', $this->statusOfPayment($first));
        self::assertSame('SUPERSEDED', $this->connection->fetchOne(
            'SELECT failure_code FROM payments WHERE provider_payment_id = :reference',
            ['reference' => $first],
        ));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM payments WHERE status IN ('PENDING', 'AUTHORIZED')",
        ));

        // A success for the abandoned attempt is stale, never a second
        // collection of the same invoice.
        $late = $this->deliver(['id' => 'evt_old', 'type' => 'payment.succeeded', 'payment_id' => $first]);
        self::assertSame('IGNORED_STALE', $this->decode($late)['outcome'] ?? null);
        self::assertSame('ISSUED', $this->statusOfInvoice());
    }

    public function testAnAttemptTheProviderWillNotAbandonRefusesASecond(): void
    {
        $provider = $this->recordingProvider(cancellable: false);
        $first = $this->startedReference();

        $response = $this->startPayment();

        // Money in flight: a second attempt beside it is one invoice paid twice.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PAYMENT_STILL_IN_FLIGHT', $this->errorOf($response)['code'] ?? null);
        self::assertSame(['authorize', 'cancel'], $provider->asked);
        self::assertSame('PENDING', $this->statusOfPayment($first));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM payments'));
    }

    public function testTheDatabaseHoldsOneLiveAttemptPerInvoice(): void
    {
        $this->startedReference();

        // Around the service, as two simultaneous starts would race past it.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO payments (tenant_id, product_id, invoice_id, provider, provider_payment_id,
                                      status, amount_minor_units, currency)
                VALUES (:tenant, :product, :invoice, 'stub', 'stub_pi_raced', 'PENDING', 3480, 'EUR')
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'invoice' => $this->invoice],
        );
    }

    // --- An invoice with the administration is still payable (2026-10-29) -----

    public function testAPaymentSettlesAnInvoiceThatIsBeingTransmitted(): void
    {
        foreach (['READY_FOR_EINVOICE', 'SUBMITTED'] as $status) {
            TestDatabase::forge(
                $this->connection,
                ['invoices'],
                'UPDATE invoices SET status = :status WHERE id = :id',
                ['status' => $status, 'id' => $this->invoice],
            );
        }

        // Accepted rather than refused as "not issued"...
        $reference = $this->startedReference();

        // ...and settled when the money arrives, rather than left owed.
        self::assertSame(200, $this->deliver([
            'id' => 'evt_ok',
            'type' => 'payment.succeeded',
            'payment_id' => $reference,
        ])->getStatusCode());

        self::assertSame('PAID', $this->statusOfInvoice());
    }

    // --- A refund the provider fails (2026-10-29) -----------------------------

    /**
     * Nothing modelled a failed refund: it stayed PENDING for ever, counted
     * against what could still be refunded, and its credit note stayed
     * declared on money that never moved.
     */
    public function testARefundTheProviderFailsStopsCountingAndLeavesItsDocumentForADecision(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        self::assertSame(202, $this->refund(['amount_minor_units' => 1_160])->getStatusCode());

        $note = $this->newestCreditNote();
        $refundReference = $this->connection->fetchOne('SELECT provider_refund_id FROM refunds');
        self::assertIsString($refundReference);

        $response = $this->deliver([
            'id' => 'evt_refund_failed',
            'type' => 'refund.failed',
            'payment_id' => $this->paymentReference(),
            'refund_id' => $refundReference,
            'failure_code' => 'expired_or_canceled_card',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FAILED', $this->connection->fetchOne('SELECT status FROM refunds'));

        // The payment never moved for it, and does not now.
        self::assertSame('SUCCEEDED', $this->statusOfPayment($this->paymentReference()));

        // The credit note is a legal document: it stays, named by the refund,
        // and the ledger says a person has to decide what answers it.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(Row::string($note, 'id'), $this->connection->fetchOne('SELECT credit_note_id FROM refunds'));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'REFUND_FAILED'"
            . " AND detail ->> 'decision_required' = 'true'"
            . " AND detail ->> 'credit_note_id' = " . $this->connection->quote(Row::string($note, 'id')),
        ));

        $refunds = $this->decode($this->request(
            'GET',
            '/api/v1/billing/payments/' . $this->paymentId(),
            $this->headers(),
        ))['refunds'] ?? null;
        self::assertIsArray($refunds);
        $shown = $refunds[0] ?? null;
        self::assertIsArray($shown);
        self::assertTrue($shown['needs_decision'] ?? null);

        // It no longer bounds what may go back — only the document still
        // standing does: 30.00 more passes the payment's ceiling and is
        // refused by the invoice's.
        $response = $this->refund(['amount_minor_units' => 3_000]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CREDIT_EXCEEDS_INVOICE', $this->errorOf($response)['code'] ?? null);
    }

    public function testAFailureDeliveredTwiceOrForNoRefundMovesNothing(): void
    {
        $this->collect();

        $response = $this->deliver([
            'id' => 'evt_refund_failed_nowhere',
            'type' => 'refund.failed',
            'payment_id' => $this->paymentReference(),
            'refund_id' => 'stub_re_never_asked',
        ]);

        // Recorded, and said to have done nothing — never "applied".
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_NOT_APPLICABLE', $this->decode($response)['outcome'] ?? null);
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'REFUND_FAILED'",
        ));
    }

    /**
     * `settleRefund` recomputed the payment's status even when no refund row
     * matched — a delivery arriving before the refund row had committed moved
     * the payment on a refund this platform had not recorded.
     */
    public function testASettlementForNoRecordedRefundMovesNothing(): void
    {
        $this->collect();

        $response = $this->deliver([
            'id' => 'evt_refunded_nowhere',
            'type' => 'refund.succeeded',
            'payment_id' => $this->paymentReference(),
            'refund_id' => 'stub_re_never_asked',
        ]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_NOT_APPLICABLE', $this->decode($response)['outcome'] ?? null);
        self::assertSame('SUCCEEDED', $this->statusOfPayment($this->paymentReference()));
    }

    public function testARefundTheProviderRefusesAtOnceWritesNoDocument(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        $this->recordingProvider(refundStatus: 'FAILED');

        $response = $this->refund(['amount_minor_units' => 1_160]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('FAILED', $this->decode($response)['status'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
        self::assertSame(580, $this->rowsMatching('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'PAYMENT_REFUNDED'",
        ));
    }

    public function testARefundTheProviderSettlesAtOnceMovesThePaymentNow(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        $this->recordingProvider(refundStatus: 'SUCCEEDED');

        $response = $this->refund(['amount_minor_units' => 1_160]);

        self::assertSame('SUCCEEDED', $this->decode($response)['status'] ?? null);
        self::assertSame('PARTIALLY_REFUNDED', $this->statusOfPayment($this->paymentReference()));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    // --- The refund's ceilings hold until it is written (2026-10-29) ---------

    /**
     * The ceilings were read with nothing held, the provider was asked, and
     * the credit note's own check could then refuse after the money had
     * left. The invoice is locked first now, so a refund that has to wait
     * waits *before* the provider is asked — proved by holding the lock from
     * another connection and watching the provider stay unasked.
     */
    public function testARefundTakesTheInvoiceBeforeItAsksTheProvider(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        $provider = $this->recordingProvider();

        $dsn = TestDatabase::dsn();
        self::assertNotNull($dsn);
        $other = ConnectionFactory::fromDsn($dsn);
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM invoices WHERE id = :id FOR UPDATE', ['id' => $this->invoice]);
        $this->connection->executeStatement("SET lock_timeout = '300ms'");

        try {
            self::assertSame(500, $this->refund(['amount_minor_units' => 1_160])->getStatusCode());
        } finally {
            $this->connection->executeStatement('RESET lock_timeout');
            $other->rollBack();
            $other->close();
        }

        self::assertSame([], $provider->asked, 'The provider was asked before the invoice was held.');
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM refunds'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    // --- What an omitted amount means, and two identical refunds -------------

    public function testAnOmittedAmountRefundsWhatIsLeftNotTheWholePayment(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();
        self::assertSame(202, $this->refund(['amount_minor_units' => 1_000])->getStatusCode());

        $response = $this->refund();

        // "Absent refunds everything still refundable": 34.80 less 10.00.
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['minor_units' => 2_480, 'currency' => 'EUR'], $this->decode($response)['amount'] ?? null);
        self::assertSame('CREDITED', $this->statusOfInvoice());

        // And with nothing left, there is nothing to refund.
        $response = $this->refund();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PAYMENT_NOT_REFUNDABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testTwoIdenticalPartialRefundsAreTwoRefunds(): void
    {
        $this->collect();

        $first = $this->refund(['amount_minor_units' => 1_000]);
        $second = $this->refund(['amount_minor_units' => 1_000]);

        self::assertSame(202, $first->getStatusCode());
        self::assertSame(202, $second->getStatusCode());

        self::assertSame(2, $this->rowsMatching('SELECT count(DISTINCT provider_refund_id) FROM refunds'));
    }

    // --- The slice that closes an invoice is what is left (2026-10-29) --------

    /**
     * Three refunds of 11.60 on a 29.00 + 5.80 invoice. Each slice rounds
     * its own base, so three of them recomputed independently credit 29.01
     * of base and 5.79 of VAT, and the fully-credited invoice left a cent of
     * VAT declared. The last slice is now what is left.
     */
    public function testPartialCreditsThatCloseTheInvoiceLeaveNothingDeclared(): void
    {
        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->collect();

        foreach ([1, 2, 3] as $slice) {
            self::assertSame(202, $this->refund(['amount_minor_units' => 1_160])->getStatusCode(), 'slice ' . $slice);
        }

        self::assertSame('CREDITED', $this->statusOfInvoice());
        self::assertSame(2_900, $this->rowsMatching('SELECT coalesce(sum(net_minor_units), 0) FROM credit_notes'));
        self::assertSame(580, $this->rowsMatching('SELECT coalesce(sum(vat_minor_units), 0) FROM credit_notes'));

        // The last document is the remainder, and still exact against the
        // money that moved.
        $last = $this->newestCreditNote();
        self::assertSame(966, Row::integer($last, 'net_minor_units'));
        self::assertSame(194, Row::integer($last, 'vat_minor_units'));
        self::assertSame(1_160, Row::integer($last, 'gross_minor_units'));

        self::assertSame(0, $this->rowsMatching('SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions'));
        self::assertSame(0, $this->rowsMatching('SELECT coalesce(sum(taxable_base), 0) FROM vat_transactions'));
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

    /**
     * Starts a payment and lets the provider say it collected — the state a
     * refund needs before it can give anything back.
     */
    private function collect(): void
    {
        $reference = $this->startedReference();
        $this->deliver(['id' => 'evt_ok', 'type' => 'payment.succeeded', 'payment_id' => $reference]);
    }

    /**
     * The fiscal fact the invoice in `setUp` would have written, had it been
     * raised through the sales chain rather than seeded.
     */
    private function seedFiscalFact(int $base, int $vat, int $rate): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO vat_transactions
                    (invoice_id, tenant_id, product_id, country, customer_tax_status, supply_type,
                     taxable_base, vat_rate, vat_amount, currency, vat_regime, rule_id, transaction_date)
                VALUES (:invoice, :tenant, :product, 'FR', 'NONE', 'DIGITAL_SERVICES',
                        :base, :rate, :vat, 'EUR', 'STANDARD', 'domestic.standard', now())
                SQL,
            [
                'invoice' => $this->invoice,
                'tenant' => $this->tenant,
                'product' => $this->product,
                'base' => $base,
                'rate' => $rate,
                'vat' => $vat,
            ],
        );
    }

    /**
     * Re-prices the seeded invoice at another rate, totals and line
     * together, so the document stays internally consistent.
     */
    private function reRateTheInvoice(int $rate): void
    {
        $vat = intdiv(2_900 * $rate + 5_000, 10_000);

        TestDatabase::forge(
            $this->connection,
            ['invoices'],
            'UPDATE invoices SET vat_minor_units = :vat, gross_minor_units = :gross WHERE id = :id',
            ['vat' => $vat, 'gross' => 2_900 + $vat, 'id' => $this->invoice],
        );
        TestDatabase::forge(
            $this->connection,
            ['invoice_lines'],
            'UPDATE invoice_lines SET vat_rate_basis_points = :rate, vat_minor_units = :vat,'
            . ' gross_minor_units = :gross WHERE invoice_id = :id',
            ['rate' => $rate, 'vat' => $vat, 'gross' => 2_900 + $vat, 'id' => $this->invoice],
        );
    }

    /**
     * Adds a second line at a reduced rate, with its own fiscal fact — the
     * invoice a partial credit cannot apportion.
     */
    private function addASecondRate(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoice_lines
                    (invoice_id, position, description, quantity, unit_price_minor_units,
                     discount_minor_units, net_minor_units, vat_rate_basis_points,
                     vat_minor_units, gross_minor_units)
                VALUES (:invoice, 2, 'Printed handbook', 1, 1000, 0, 1000, 550, 55, 1055)
                SQL,
            ['invoice' => $this->invoice],
        );

        TestDatabase::forge(
            $this->connection,
            ['invoices'],
            'UPDATE invoices SET net_minor_units = 3900, vat_minor_units = 635, gross_minor_units = 4535'
            . ' WHERE id = :id',
            ['id' => $this->invoice],
        );

        $this->seedFiscalFact(2_900, 580, 2_000);
        $this->seedFiscalFact(1_000, 55, 550);
    }

    /**
     * A second invoice, issued by whoever `$issuer` names — null being the
     * platform.
     */
    private function anotherInvoice(?string $issuer): string
    {
        $id = $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, issuer_tenant_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units,
                     issued_at, supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, CAST(:issuer AS uuid), '2026-000002', 'ISSUED', 'EUR',
                        1000, 200, 1200, now(),
                        CAST('{"legal_name":"Atlas SAS"}' AS jsonb),
                        CAST('{"legal_name":"Acme SARL"}' AS jsonb))
                RETURNING id
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'issuer' => $issuer],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoice_lines
                    (invoice_id, position, description, quantity, unit_price_minor_units,
                     discount_minor_units, net_minor_units, vat_rate_basis_points,
                     vat_minor_units, gross_minor_units)
                VALUES (:invoice, 1, 'Something else', 1, 1000, 0, 1000, 2000, 200, 1200)
                SQL,
            ['invoice' => $id],
        );

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function newestCreditNote(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, number, issuer_tenant_id, net_minor_units, vat_minor_units, gross_minor_units'
            . ' FROM credit_notes ORDER BY created_at DESC, number DESC LIMIT 1',
        );

        self::assertIsArray($row, 'A refund raises a credit note.');

        return $row;
    }

    /**
     * The reversing fiscal fact a credit note wrote.
     *
     * @return array<string, mixed>
     */
    private function reversalOf(string $creditNoteId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT taxable_base, vat_amount, vat_rate, vat_regime, rule_id, country, issuer_tenant_id'
            . ' FROM vat_transactions WHERE credit_note_id = :note',
            ['note' => $creditNoteId],
        );

        self::assertIsArray($row, 'A credit note writes its reversing fact.');

        return $row;
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

    /**
     * The stub, recording what it is asked, in place of the plain one.
     */
    private function recordingProvider(bool $cancellable = true, string $refundStatus = 'PENDING'): RecordingPaymentProvider
    {
        $provider = new RecordingPaymentProvider(new StubPaymentProvider(self::SECRET), $cancellable, $refundStatus);

        $this->override([PaymentProviders::class => new PaymentProviders([$provider])]);

        return $provider;
    }

    private function paymentReference(): string
    {
        $reference = $this->connection->fetchOne('SELECT provider_payment_id FROM payments');
        self::assertIsString($reference);

        return $reference;
    }

    private function paymentId(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM payments');
        self::assertIsString($id);

        return $id;
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

    /**
     * One payment as the list answers it, which is the shape the screen reads.
     *
     * The list rather than the single read, because `invoice_settled` comes
     * from the invoice beside the payment and the list is where that join
     * lives.
     *
     * @return array<string, mixed>
     */
    private function listedPayment(string $providerReference): array
    {
        $body = $this->decode($this->request('GET', '/api/v1/billing/payments', $this->headers()));
        $payments = $body['payments'] ?? null;
        self::assertIsArray($payments);

        foreach ($payments as $payment) {
            if (is_array($payment) && ($payment['provider_payment_id'] ?? null) === $providerReference) {
                /** @var array<string, mixed> $payment */
                return $payment;
            }
        }

        self::fail('no payment in the list carries the reference ' . $providerReference);
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
