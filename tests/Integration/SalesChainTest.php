<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\EInvoice\Infrastructure\StubEInvoiceProvider;
use App\EInvoice\Service\EInvoiceProviders;
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
 *     Quote → Order → Subscription → Invoice → (Payment) → e-invoice
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

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    [
                        'sales.read', 'sales.manage',
                        'billing.read', 'billing.manage',
                        'subscription.read', 'entitlements.read',
                    ],
                ),
            ]),
        ]);

        $this->saveProfile();
    }

    // --- The chain -----------------------------------------------------------

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

        self::assertSame('COMPLETED', $fulfilled['status'] ?? null);
        // §20's chain readable off one document, which is the point of it.
        self::assertIsString($fulfilled['subscription_id'] ?? null);
        self::assertIsString($fulfilled['invoice_id'] ?? null);
        self::assertIsString($fulfilled['completed_at'] ?? null);

        // The quote was accepted by the same act.
        self::assertSame('ACCEPTED', $this->statusOf('quotes', $quoteId));

        // And the subscription actually entitles the tenant.
        $mine = $this->decode($this->request('GET', '/api/v1/subscription', $this->headers()));
        self::assertIsArray($mine['subscription'] ?? null);
    }

    public function testTheInvoiceBillsWhatWasQuotedNotWhatTheOfferBecame(): void
    {
        $quoteId = $this->quotedId();

        // The offer is repriced after the quote went out — the exact thing a
        // quote exists to protect the customer from.
        $this->connection->executeStatement(
            'UPDATE offer_versions SET price_minor_units = 9900 WHERE id = :version',
            ['version' => $this->offerVersion],
        );

        $orderId = $this->decode($this->accept($quoteId))['id'] ?? null;
        self::assertIsString($orderId);

        $invoiceId = $this->decode($this->fulfil($orderId))['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        $invoice = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers()),
        );

        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $invoice['gross'] ?? null);
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

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ORDER_NOT_FULFILLABLE', $this->errorOf($again)['code'] ?? null);
        // One subscription and one invoice, not two.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM subscriptions'));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testACompletedOrderCannotBeCancelled(): void
    {
        $orderId = $this->orderedId();
        $this->fulfil($orderId);

        $response = $this->request('POST', '/api/v1/sales/orders/' . $orderId . '/cancel', $this->headers());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ORDER_NOT_CANCELLABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testFulfilmentIsRefusedWithoutABillingProfile(): void
    {
        $this->connection->executeStatement('DELETE FROM billing_profiles');

        $orderId = $this->orderedId();
        $response = $this->fulfil($orderId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($response)['code'] ?? null);
        // Nothing was written: the whole fulfilment is one transaction.
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
        self::assertIsString($transmission['provider_document_id'] ?? null);
        self::assertSame('SUBMITTED', $this->statusOf('invoices', $invoiceId));

        $document = $transmission['provider_document_id'];
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
                    (:product, 'vat_rates', CAST('{"FR": 2000, "default": 0}' AS jsonb))
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
            "INSERT INTO offers (product_id, plan_id, code, name)"
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
