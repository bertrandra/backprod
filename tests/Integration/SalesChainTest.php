<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\EInvoice\Infrastructure\StubEInvoiceProvider;
use App\EInvoice\Service\EInvoiceProviders;
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
 * §20's chain end to end, through the real pipeline and the real database:
 *
 *     Quote → Order → Invoice → Payment → Subscription → e-invoice
 *
 * The order of those middle two is the gate. A subscription used to start the
 * moment an order was fulfilled, on the assumption the money would follow;
 * now the invoice is raised first and what was bought starts when that
 * invoice is paid — by card through the provider's webhook, or by transfer
 * through an operator reconciling it.
 *
 * Plus §37.4's last billing scenario, invoice rejected, which is where the
 * transmission history earns its separate table.
 */
#[CoversNothing]
final class SalesChainTest extends DatabaseApiTestCase
{
    private const SECRET = 'einvoice-test-secret';

    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $offer = '';
    private string $offerVersion = '';

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

            EInvoiceProviders::class => new EInvoiceProviders([new StubEInvoiceProvider(self::SECRET)]),

            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    [
                        'sales.read', 'sales.manage',
                        'billing.read', 'billing.manage', 'billing.pay',
                        'payments.read', 'payments.manage',
                        'subscription.read', 'entitlements.read',
                        'tax.read', 'tax.manage',
                    ],
                ),
            ]),
        ]);

        $this->saveProfile();
        // Acme is a business: a quote is raised for a B2B customer and refused
        // for a private person (the test below), and everything in this chain
        // starts from a quote.
        $this->declareBusiness();
    }

    // --- The chain -----------------------------------------------------------

    public function testAPrivatePersonIsNotQuotedButBuysAtTheListedPrice(): void
    {
        $this->request('PUT', '/api/v1/tax/profile', $this->headers(), $this->json(['customer_kind' => 'B2C', 'country_code' => 'FR']));

        $refused = $this->quote();

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('QUOTE_REQUIRES_BUSINESS_CUSTOMER', $this->errorOf($refused)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM quotes'));

        // Buying is not a quote: the same person may still order at the price.
        $order = $this->request('POST', '/api/v1/sales/orders', $this->headers(), $this->json(['offer_id' => $this->offer]));
        self::assertSame(201, $order->getStatusCode());
    }

    public function testAQuoteIsPricedFromTheOfferAndHeldUntilADate(): void
    {
        $response = $this->quote();

        self::assertSame(201, $response->getStatusCode());

        $quote = $this->decode($response);
        self::assertSame('SENT', $quote['status'] ?? null);
        self::assertTrue($quote['open'] ?? null);
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $quote['net'] ?? null);
        self::assertSame(['minor_units' => 580, 'currency' => 'EUR'], $quote['vat'] ?? null);
        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $quote['gross'] ?? null);
        self::assertIsString($quote['valid_until'] ?? null);

        // The customer as they were when the quote was sent, like an invoice.
        $customer = $quote['customer'] ?? null;
        self::assertIsArray($customer);
        self::assertSame('Acme SARL', $customer['legal_name'] ?? null);
    }

    public function testTheWholeChainRunsFromQuoteToInvoice(): void
    {
        $quoteId = $this->quotedId();

        $order = $this->decode($this->accept($quoteId));
        self::assertSame('PENDING', $order['status'] ?? null);
        self::assertSame($quoteId, $order['quote_id'] ?? null);
        self::assertArrayHasKey('subscription_id', $order);
        self::assertNull($order['subscription_id']);
        self::assertArrayHasKey('invoice_id', $order);
        self::assertNull($order['invoice_id']);

        $orderId = $order['id'] ?? null;
        self::assertIsString($orderId);

        $fulfilled = $this->decode($this->fulfil($orderId));

        // The invoice is raised; the subscription is not. That is the gate:
        // what was bought starts when the money arrives, not when somebody
        // presses fulfil.
        self::assertSame('AWAITING_PAYMENT', $fulfilled['status'] ?? null);
        self::assertIsString($fulfilled['invoice_id'] ?? null);
        self::assertArrayHasKey('subscription_id', $fulfilled);
        self::assertNull($fulfilled['subscription_id']);
        self::assertArrayHasKey('completed_at', $fulfilled);
        self::assertNull($fulfilled['completed_at']);

        // The quote was accepted by the same act.
        self::assertSame('ACCEPTED', $this->statusOf('quotes', $quoteId));

        // Nothing is switched on yet, which is the whole point.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM subscriptions'));

        $this->payInFull($orderId);

        $completed = $this->decode($this->showOrder($orderId));

        // §20's chain readable off one document, which is the point of it.
        self::assertSame('COMPLETED', $completed['status'] ?? null);
        self::assertIsString($completed['subscription_id'] ?? null);
        self::assertIsString($completed['invoice_id'] ?? null);
        self::assertIsString($completed['completed_at'] ?? null);

        // And now the subscription actually entitles the tenant.
        $mine = $this->decode($this->request('GET', '/api/v1/subscription', $this->headers()));
        self::assertIsArray($mine['subscription'] ?? null);
    }

    public function testTheInvoiceBillsWhatWasQuotedNotWhatTheOfferBecame(): void
    {
        $quoteId = $this->quotedId();

        $orderId = $this->decode($this->accept($quoteId))['id'] ?? null;
        self::assertIsString($orderId);

        $invoiceId = $this->decode($this->fulfil($orderId))['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        $invoice = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers()),
        );

        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $invoice['gross'] ?? null);

        // And now the offer becomes something else, the way production does
        // it: the sold version retires and a dearer one takes its place on
        // sale. Repricing the sold version in place is the blunter version of
        // this and ADR-033 has made it impossible, so the legal sequence is
        // the strongest form left.
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :version",
            ['version' => $this->offerVersion],
        );
        $this->connection->executeStatement(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 2, 'ACTIVE', 'MONTHLY', 9900, 'EUR', now())",
            ['offer' => $this->offer],
        );

        $reread = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers()),
        );

        self::assertSame($invoice['gross'] ?? null, $reread['gross'] ?? null);
    }

    /**
     * The guard in InvoiceThenSubscribe matches on the version the order
     * recorded, not on "the offer still has something on sale". Withdrawal is
     * covered below; this is the case a reader actually worries about, where
     * a dearer version stands ready to be picked up silently.
     */
    public function testAnOrderIsNotSilentlyRepricedOntoANewerOfferVersion(): void
    {
        $orderId = $this->orderedId();

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :version",
            ['version' => $this->offerVersion],
        );
        $this->connection->executeStatement(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 2, 'ACTIVE', 'MONTHLY', 9900, 'EUR', now())",
            ['offer' => $this->offer],
        );

        $response = $this->fulfil($orderId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('OFFER_NO_LONGER_ON_SALE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testAnOrderCanBePlacedWithoutAQuote(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/sales/orders',
            $this->headers(),
            $this->json(['offer_id' => $this->offer]),
        );

        self::assertSame(201, $response->getStatusCode());

        $order = $this->decode($response);
        self::assertSame('PENDING', $order['status'] ?? null);
        self::assertArrayHasKey('quote_id', $order);
        self::assertNull($order['quote_id']);
    }

    // --- What the chain refuses ----------------------------------------------

    public function testALapsedQuoteCannotBeAccepted(): void
    {
        $quoteId = $this->quotedId();

        // The status column is left saying SENT, which is exactly the state a
        // platform with no sweeper is in. The clock decides.
        $this->connection->executeStatement(
            "UPDATE quotes SET valid_until = now() - interval '1 day' WHERE id = :id",
            ['id' => $quoteId],
        );

        $response = $this->accept($quoteId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('QUOTE_EXPIRED', $this->errorOf($response)['code'] ?? null);
        self::assertSame('SENT', $this->statusOf('quotes', $quoteId));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM orders'));
    }

    public function testOneQuoteCannotBecomeTwoOrders(): void
    {
        $quoteId = $this->quotedId();

        self::assertSame(201, $this->accept($quoteId)->getStatusCode());

        $again = $this->accept($quoteId);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('INVALID_QUOTE_TRANSITION', $this->errorOf($again)['code'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM orders'));
    }

    public function testARejectedQuoteCannotBeAccepted(): void
    {
        $quoteId = $this->quotedId();

        $this->request('POST', '/api/v1/sales/quotes/' . $quoteId . '/reject', $this->headers());

        self::assertSame('REJECTED', $this->statusOf('quotes', $quoteId));
        self::assertSame(409, $this->accept($quoteId)->getStatusCode());
    }

    public function testAnOrderCannotBeFulfilledTwice(): void
    {
        $orderId = $this->orderedId();

        self::assertSame(200, $this->fulfil($orderId)->getStatusCode());

        $again = $this->fulfil($orderId);

        // An order awaiting payment already has a numbered invoice against
        // it, and numbering is gapless: a second one could not be deleted.
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ORDER_NOT_FULFILLABLE', $this->errorOf($again)['code'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testAnInvoicedOrderCannotBeCancelled(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        // Still only awaiting payment — but the invoice has been issued, and
        // an issued document is undone by crediting it, not by cancelling
        // the order that raised it.
        self::assertSame('AWAITING_PAYMENT', $this->statusOf('orders', $orderId));

        $response = $this->request('POST', '/api/v1/sales/orders/' . $orderId . '/cancel', $this->headers());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ORDER_NOT_CANCELLABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnOrderWithNoInvoiceYetCanStillBeCancelled(): void
    {
        $orderId = $this->orderedId();

        $response = $this->request('POST', '/api/v1/sales/orders/' . $orderId . '/cancel', $this->headers());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('CANCELLED', $this->statusOf('orders', $orderId));
    }

    public function testFulfilmentIsRefusedWithoutABillingProfile(): void
    {
        $this->connection->executeStatement('DELETE FROM billing_profiles');

        $orderId = $this->orderedId();
        $response = $this->fulfil($orderId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($response)['code'] ?? null);
        // Nothing was written: raising the invoice and parking the order is
        // one transaction.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
        self::assertSame('PENDING', $this->statusOf('orders', $orderId));
    }

    public function testFulfilmentIsRefusedWhenTheOfferHasBeenWithdrawn(): void
    {
        $orderId = $this->orderedId();

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ARCHIVED' WHERE id = :version",
            ['version' => $this->offerVersion],
        );

        $response = $this->fulfil($orderId);

        // Fulfilling against whatever the offer became would bill the
        // customer for terms they never agreed to.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('OFFER_NO_LONGER_ON_SALE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testSellingRequiresThePermission(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['sales.read']),
            ]),
        ]);

        $response = $this->quote();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM quotes'));
    }

    // --- E-invoicing (§25.1) -------------------------------------------------

    public function testAnInvoiceIsTransmittedAndAccepted(): void
    {
        $invoiceId = $this->invoicedId();

        $response = $this->transmit($invoiceId);

        // 202: lodged, not judged. Whether it is accepted is the platform's
        // answer to give.
        self::assertSame(202, $response->getStatusCode());

        $transmission = $this->decode($response);
        self::assertSame('SUBMITTED', $transmission['status'] ?? null);
        self::assertSame('SUBMITTED', $this->statusOf('invoices', $invoiceId));

        // Asserted once. Narrowing the offset and then narrowing the variable
        // read from it makes the second assertion dead.
        $document = $transmission['provider_document_id'] ?? null;
        self::assertIsString($document);

        $this->deliver(['id' => 'ev_ok', 'type' => 'document.accepted', 'document_id' => $document]);

        self::assertSame('ACCEPTED', $this->statusOf('invoices', $invoiceId));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM einvoice_transmissions WHERE status = 'ACCEPTED'",
        ));
    }

    /**
     * §37.4's last billing scenario, and where the transmission history earns
     * its own table: rejected, corrected, transmitted again, accepted. Both
     * attempts stay visible.
     */
    public function testARejectedInvoiceIsCorrectedAndTransmittedAgain(): void
    {
        $invoiceId = $this->invoicedId();

        $first = $this->decode($this->transmit($invoiceId));
        $document = $first['provider_document_id'] ?? null;
        self::assertIsString($document);

        $this->deliver([
            'id' => 'ev_no',
            'type' => 'document.rejected',
            'document_id' => $document,
            'rejection_code' => 'MISSING_SIREN',
            'rejection_reason' => 'The customer SIREN is absent.',
        ]);

        self::assertSame('REJECTED', $this->statusOf('invoices', $invoiceId));

        $transmissions = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId . '/transmissions', $this->headers()),
        )['transmissions'] ?? null;
        self::assertIsArray($transmissions);
        self::assertCount(1, $transmissions);

        $rejected = $transmissions[0] ?? null;
        self::assertIsArray($rejected);
        self::assertSame('MISSING_SIREN', $rejected['rejection_code'] ?? null);

        // Corrected and sent again. A rejected invoice may go back to
        // READY_FOR_EINVOICE — that is §25.1's remedy.
        $second = $this->transmit($invoiceId);
        self::assertSame(202, $second->getStatusCode());

        $all = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId . '/transmissions', $this->headers()),
        )['transmissions'] ?? null;
        self::assertIsArray($all);

        // Both attempts, not just the latest: proving what happened is the
        // point of keeping them.
        self::assertCount(2, $all);
    }

    public function testAnInvoiceAwaitingAVerdictIsNotTransmittedAgain(): void
    {
        $invoiceId = $this->invoicedId();
        $this->transmit($invoiceId);

        $again = $this->transmit($invoiceId);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('EINVOICE_ALREADY_IN_FLIGHT', $this->errorOf($again)['code'] ?? null);
        // Two documents in front of the administration for one invoice is the
        // failure this refuses.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM einvoice_transmissions'));
    }

    public function testReplayingAPlatformVerdictAppliesItOnce(): void
    {
        $invoiceId = $this->invoicedId();
        $document = $this->decode($this->transmit($invoiceId))['provider_document_id'] ?? null;
        self::assertIsString($document);

        $delivery = ['id' => 'ev_once', 'type' => 'document.accepted', 'document_id' => $document];

        $first = $this->deliver($delivery);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('APPLIED', $this->decode($first)['outcome'] ?? null);

        $second = $this->deliver($delivery);

        self::assertSame(202, $second->getStatusCode());
        self::assertSame('DUPLICATE', $this->decode($second)['outcome'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM einvoice_events'));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM einvoice_transmissions WHERE status = 'ACCEPTED'",
        ));
        self::assertSame('ACCEPTED', $this->statusOf('invoices', $invoiceId));
    }

    public function testAVerdictArrivingLateDoesNotWalkTheRecordBackwards(): void
    {
        $invoiceId = $this->invoicedId();
        $document = $this->decode($this->transmit($invoiceId))['provider_document_id'] ?? null;
        self::assertIsString($document);

        $this->deliver(['id' => 'ev_yes', 'type' => 'document.accepted', 'document_id' => $document]);

        $late = $this->deliver(['id' => 'ev_late', 'type' => 'document.submitted', 'document_id' => $document]);

        self::assertSame(202, $late->getStatusCode());
        self::assertSame('IGNORED_STALE', $this->decode($late)['outcome'] ?? null);
        self::assertSame('ACCEPTED', $this->statusOf('invoices', $invoiceId));
    }

    public function testAnUnsignedPlatformDeliveryIsRefused(): void
    {
        $body = $this->json(['id' => 'ev_x', 'type' => 'document.accepted', 'document_id' => 'stub_doc_1']);

        $response = $this->request('POST', '/api/v1/webhooks/einvoice/stub', [], $body);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM einvoice_events'));
    }

    public function testAVerdictForADocumentNobodyKnowsIsRecordedNotDiscarded(): void
    {
        $response = $this->deliver([
            'id' => 'ev_orphan',
            'type' => 'document.accepted',
            'document_id' => 'stub_doc_never_seen',
        ]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('IGNORED_UNKNOWN_TRANSMISSION', $this->decode($response)['outcome'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM einvoice_events'));
    }

    public function testADraftInvoiceCannotBeTransmitted(): void
    {
        $invoiceId = $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, status, currency, net_minor_units,
                     vat_minor_units, gross_minor_units, supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, 'DRAFT', 'EUR', 100, 20, 120, '{}', '{}')
                RETURNING id
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product],
        );

        $response = $this->transmit($invoiceId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('INVOICE_NOT_TRANSMITTABLE', $this->errorOf($response)['code'] ?? null);
    }

    // --- The gate ------------------------------------------------------------

    public function testAnInvoiceSettledByHandStartsTheSubscriptionToo(): void
    {
        $orderId = $this->orderedId();

        self::assertSame(200, $this->fulfil($orderId)->getStatusCode());

        $invoiceId = $this->invoiceOf($orderId);

        // No card, no webhook: an operator matching a bank transfer. §25 makes
        // this as real a way to be paid as any, so it has to release the sale
        // as well — otherwise every transfer-paying customer pays and gets
        // nothing.
        $response = $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $invoiceId . '/pay',
            $this->headers(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('COMPLETED', $this->statusOf('orders', $orderId));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
    }

    public function testAPaymentThatFailsStartsNothing(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        $reference = $this->startedPayment($orderId);

        $this->deliverPayment([
            'id' => 'evt_failed_1',
            'type' => 'payment.failed',
            'payment_id' => $reference,
            'failure_code' => 'CARD_DECLINED',
        ]);

        // The order is still waiting, which is exactly right: the customer
        // can try again, and nothing was switched on for a payment that did
        // not arrive.
        self::assertSame('AWAITING_PAYMENT', $this->statusOf('orders', $orderId));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
    }

    public function testReplayingTheSuccessStartsExactlyOneSubscription(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        $reference = $this->startedPayment($orderId);
        $event = ['id' => 'evt_once', 'type' => 'payment.succeeded', 'payment_id' => $reference];

        $this->deliverPayment($event);
        $this->deliverPayment($event);
        $this->deliverPayment($event);

        // One subscription, and one ledger entry saying the order completed.
        // Counting the ledger rather than the status matters: a status is
        // idempotent by accident, a ledger row is not.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
        self::assertSame(
            1,
            $this->rowsMatching("SELECT count(*) FROM financial_events WHERE type = 'ORDER_COMPLETED'"),
        );
        self::assertSame('COMPLETED', $this->statusOf('orders', $orderId));
    }

    /**
     * One live subscription per tenant and product is a partial unique
     * index, and an index refuses last — after the order, the numbered
     * invoice and the card. The operator met exactly that on their own
     * deployment on 2026-09-17: a second offer bought beside a live
     * subscription, charged twice over, and a webhook that could only ever
     * fail. So the sale is refused where it is placed, before any document
     * exists, and a quote accepted into one is refused the same way.
     */
    public function testASecondOrderIsRefusedWhileTheSubscriptionIsLive(): void
    {
        $first = $this->orderedId();
        $this->fulfil($first);
        $reference = $this->startedPayment($first);
        self::assertSame(200, $this->deliverPayment(['id' => 'evt_first', 'type' => 'payment.succeeded', 'payment_id' => $reference])->getStatusCode());
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));

        $orders = $this->rowsMatching('SELECT count(*) FROM orders');
        $invoices = $this->rowsMatching('SELECT count(*) FROM invoices');

        // Quoting is still allowed — a quote is a document, and the next
        // term may well be quoted while this one runs — but accepting it
        // into an order is not.
        $response = $this->accept($this->quotedId());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SUBSCRIPTION_ALREADY_ACTIVE', $this->errorOf($response)['code'] ?? null);
        $details = $this->errorOf($response)['details'] ?? null;
        self::assertIsArray($details);
        // Named, so the refusal points at what to change rather than at a wall.
        self::assertIsString($details['subscription_id'] ?? null);

        $direct = $this->request('POST', '/api/v1/sales/orders', $this->headers(), $this->json(['offer_id' => $this->offer]));

        self::assertSame(409, $direct->getStatusCode());
        self::assertSame('SUBSCRIPTION_ALREADY_ACTIVE', $this->errorOf($direct)['code'] ?? null);

        // Nothing written, nothing numbered: the refusal is before the order.
        self::assertSame($orders, $this->rowsMatching('SELECT count(*) FROM orders'));
        self::assertSame($invoices, $this->rowsMatching('SELECT count(*) FROM invoices'));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
    }

    /**
     * What the refusal at placement cannot reach: two orders opened before
     * either was paid, and the second paid after the first started the
     * subscription. The money has arrived by then. Throwing would roll the
     * delivery back — payment PENDING, invoice ISSUED, the provider retrying
     * a delivery that can never succeed, a customer charged for something
     * nobody recorded — which is how it used to answer, and what the
     * operator found. Now the money is recorded, the sale is *held*, and the
     * ledger says why.
     */
    public function testAPaidOrderThatCannotStartASubscriptionIsHeldNotFailed(): void
    {
        $first = $this->orderedId();
        $second = $this->orderedId();
        $this->fulfil($first);
        $this->fulfil($second);

        $firstReference = $this->startedPayment($first);
        $secondReference = $this->startedPayment($second);

        $firstEvent = ['id' => 'evt_first', 'type' => 'payment.succeeded', 'payment_id' => $firstReference];
        self::assertSame(200, $this->deliverPayment($firstEvent)->getStatusCode());
        self::assertSame('COMPLETED', $this->statusOf('orders', $first));

        $response = $this->deliverPayment(['id' => 'evt_second', 'type' => 'payment.succeeded', 'payment_id' => $secondReference]);

        // Accepted, so the provider stops retrying; the money is a fact.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('APPLIED', $this->decode($response)['outcome'] ?? null);
        self::assertSame(1, $this->rowsMatching("SELECT count(*) FROM payments WHERE provider_payment_id = '{$secondReference}' AND status = 'SUCCEEDED'"));
        self::assertSame('PAID', $this->statusOf('invoices', $this->invoiceOf($second)));

        // And nothing pretended about the sale: one subscription, the
        // second order still where it was, and a ledger row naming why.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
        self::assertSame('AWAITING_PAYMENT', $this->statusOf('orders', $second));
        $shown = $this->decode($this->showOrder($second));
        self::assertArrayHasKey('subscription_id', $shown);
        self::assertNull($shown['subscription_id']);
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM financial_events WHERE type = 'ORDER_HELD' AND order_id = '{$second}'"
            . " AND detail->>'reason' = 'SUBSCRIPTION_ALREADY_ACTIVE' AND detail->>'subscription_id' IS NOT NULL",
        ));
        self::assertSame(1, $this->rowsMatching("SELECT count(*) FROM financial_events WHERE type = 'ORDER_COMPLETED'"));

        // A genuine replay of the first is still a duplicate, still 202.
        $again = $this->deliverPayment($firstEvent);
        self::assertSame(202, $again->getStatusCode());
        self::assertSame('DUPLICATE', $this->decode($again)['outcome'] ?? null);
    }

    public function testAnOfferWithdrawnAfterInvoicingStillActivatesWhenPaid(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        $reference = $this->startedPayment($orderId);

        // Withdrawn between the invoice going out and the money arriving.
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ARCHIVED' WHERE id = :version",
            ['version' => $this->offerVersion],
        );

        $this->deliverPayment(['id' => 'evt_late', 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        // Refusing here would take a customer's money and give them nothing.
        // What is on sale governs what may be *invoiced*; what was sold
        // governs what is activated.
        self::assertSame('COMPLETED', $this->statusOf('orders', $orderId));
        self::assertSame(
            $this->offerVersion,
            $this->connection->fetchOne('SELECT offer_version_id FROM subscriptions'),
        );
    }

    public function testAnOrderWithNothingToCollectNeedsNoPayment(): void
    {
        $free = $this->seedFreeOffer();

        $order = $this->decode($this->request(
            'POST',
            '/api/v1/sales/orders',
            $this->headers(),
            $this->json(['offer_id' => $free]),
        ));

        $orderId = $order['id'] ?? null;
        self::assertIsString($orderId);

        $fulfilled = $this->decode($this->fulfil($orderId));

        // Nothing to pay, so nothing to wait for. Parking a free order behind
        // a payment would strand it forever.
        self::assertSame('COMPLETED', $fulfilled['status'] ?? null);
        self::assertIsString($fulfilled['subscription_id'] ?? null);
        self::assertIsString($fulfilled['invoice_id'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM payments'));
    }

    public function testThePaidInvoiceNamesTheSubscriptionItStarted(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        $invoiceId = $this->invoiceOf($orderId);

        // Raised before the subscription existed, so it cannot name it yet.
        self::assertNull($this->connection->fetchOne(
            'SELECT subscription_id FROM invoices WHERE id = :id',
            ['id' => $invoiceId],
        ));

        $this->payInFull($orderId);

        // And now it does, so "what has this subscription been billed?" is
        // answerable without going through the order.
        self::assertIsString($this->connection->fetchOne(
            'SELECT subscription_id FROM invoices WHERE id = :id',
            ['id' => $invoiceId],
        ));
    }

    public function testThePaymentRecordsTheSaleItSettled(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);
        $this->payInFull($orderId);

        // §20 wants the chain followable in both directions, and a
        // reconciliation starts from the payment.
        self::assertSame(
            $orderId,
            $this->connection->fetchOne('SELECT order_id FROM payments'),
        );
    }

    // --- Helpers -------------------------------------------------------------

    private function quote(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/sales/quotes',
            $this->headers(),
            $this->json(['offer_id' => $this->offer]),
        );
    }

    private function quotedId(): string
    {
        $response = $this->quote();
        self::assertSame(201, $response->getStatusCode());

        $id = $this->decode($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function accept(string $quoteId): ResponseInterface
    {
        return $this->request('POST', '/api/v1/sales/quotes/' . $quoteId . '/accept', $this->headers());
    }

    private function orderedId(): string
    {
        $id = $this->decode($this->accept($this->quotedId()))['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function fulfil(string $orderId): ResponseInterface
    {
        return $this->request('POST', '/api/v1/sales/orders/' . $orderId . '/fulfil', $this->headers());
    }

    private function invoicedId(): string
    {
        $invoiceId = $this->decode($this->fulfil($this->orderedId()))['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        return $invoiceId;
    }

    private function showOrder(string $orderId): ResponseInterface
    {
        return $this->request('GET', '/api/v1/sales/orders/' . $orderId, $this->headers());
    }

    private function invoiceOf(string $orderId): string
    {
        $invoiceId = $this->decode($this->showOrder($orderId))['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        return $invoiceId;
    }

    /**
     * Starts a payment against the order's invoice and returns the provider's
     * handle for it, which is what a webhook names.
     */
    private function startedPayment(string $orderId): string
    {
        $response = $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $this->invoiceOf($orderId) . '/payments',
            $this->headers(),
        );

        self::assertSame(201, $response->getStatusCode());

        $reference = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments ORDER BY created_at DESC LIMIT 1',
        );
        self::assertIsString($reference);

        return $reference;
    }

    private function payInFull(string $orderId): void
    {
        $reference = $this->startedPayment($orderId);

        $response = $this->deliverPayment([
            'id' => 'evt_paid_' . substr($reference, -6),
            'type' => 'payment.succeeded',
            'payment_id' => $reference,
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $event
     */
    private function deliverPayment(array $event): ResponseInterface
    {
        $body = $this->json($event);

        return $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => (new StubPaymentProvider(self::SECRET))->sign($body)],
            $body,
        );
    }

    /**
     * An offer priced at nothing, which the schema allows and a free tier is.
     */
    private function seedFreeOffer(): string
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

    private function transmit(string $invoiceId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $invoiceId . '/transmit',
            $this->headers(),
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function deliver(array $event): ResponseInterface
    {
        $body = $this->json($event);

        return $this->request(
            'POST',
            '/api/v1/webhooks/einvoice/stub',
            [StubEInvoiceProvider::SIGNATURE_HEADER => (new StubEInvoiceProvider(self::SECRET))->sign($body)],
            $body,
        );
    }

    private function declareBusiness(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/tax/profile',
            $this->headers(),
            $this->json(['customer_kind' => 'B2B', 'country_code' => 'FR', 'taxable_person' => true]),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function saveProfile(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json([
                'legal_name' => 'Acme SARL',
                'country_code' => 'FR',
                'city' => 'Paris',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
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

        $this->offerVersion = $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $this->offer],
        );
    }

    private function statusOf(string $table, string $id): string
    {
        // The table name is a literal from this class's own call sites.
        $status = $this->connection->fetchOne(
            sprintf('SELECT status FROM %s WHERE id = :id', $table),
            ['id' => $id],
        );

        self::assertIsString($status);

        return $status;
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
