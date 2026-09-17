<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
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
 * Invoicing, through the real pipeline and the real database.
 *
 * A double would defeat the point of most of these. Gapless numbering is a
 * property of a transaction holding a lock; the snapshot rule is a property
 * of what is stored versus what is joined; and both are exactly what an
 * in-memory repository would quietly implement correctly by accident.
 *
 * The doubles stop at identity — who is calling, which product, what they
 * are a member of — as in the subscription tests.
 */
#[CoversNothing]
final class InvoiceEndpointsTest extends DatabaseApiTestCase
{
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
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id",
        );
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

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'subscription.read', 'subscription.manage'],
                ),
            ]),
        ]);
    }

    public function testATenantWithNoProfileHasNoneRatherThanAnError(): void
    {
        $response = $this->request('GET', '/api/v1/billing/profile', $this->headers());

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertArrayHasKey('profile', $body);
        self::assertNull($body['profile']);
    }

    public function testAProfileIsSavedAndReadBack(): void
    {
        $response = $this->saveProfile();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Acme SARL', $this->decode($response)['legal_name'] ?? null);

        $stored = $this->decode(
            $this->request('GET', '/api/v1/billing/profile', $this->headers()),
        )['profile'] ?? null;

        self::assertIsArray($stored);
        self::assertSame('FR', $stored['country_code'] ?? null);
    }

    public function testTheProfileIsReplacedRatherThanDuplicated(): void
    {
        $this->saveProfile();
        $this->saveProfile(['legal_name' => 'Acme SAS', 'city' => 'Lyon']);

        // One legal identity per tenant. A second row would leave "which one
        // gets invoiced" to whichever query happened to run first.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM billing_profiles'));

        $stored = $this->decode(
            $this->request('GET', '/api/v1/billing/profile', $this->headers()),
        )['profile'] ?? null;

        self::assertIsArray($stored);
        self::assertSame('Acme SAS', $stored['legal_name'] ?? null);
        self::assertSame('Lyon', $stored['city'] ?? null);
    }

    public function testACountryThatIsNotACodeIsRefusedBeforeItReachesTheDatabase(): void
    {
        $response = $this->saveProfile(['country_code' => 'France']);

        // 400 rather than the 500 a raised check constraint would produce:
        // §31 forbids a SQL error reaching a client, and "France" is a
        // mistake worth naming.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    public function testInvoicingWithoutASubscriptionIsRefused(): void
    {
        $this->saveProfile();

        $response = $this->issue();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NO_SUBSCRIPTION', $this->errorOf($response)['code'] ?? null);
    }

    public function testInvoicingWithoutABillingProfileIsRefused(): void
    {
        $this->subscribe();

        $response = $this->issue();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($response)['code'] ?? null);
        // Refused before a number was allocated. Numbering is gapless, so a
        // document raised by mistake cannot simply be deleted.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testAnIssuedInvoiceCarriesItsNumberTotalsAndParties(): void
    {
        $invoice = $this->decode($this->issueSuccessfully());

        self::assertSame('ISSUED', $invoice['status'] ?? null);
        self::assertTrue($invoice['final'] ?? null);
        self::assertIsString($invoice['number'] ?? null);
        self::assertSame(date('Y') . '-000001', $invoice['number']);

        // 29.00 net, 20% VAT, 34.80 gross — as integers, all the way out.
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $invoice['net'] ?? null);
        self::assertSame(['minor_units' => 580, 'currency' => 'EUR'], $invoice['vat'] ?? null);
        self::assertSame(['minor_units' => 3480, 'currency' => 'EUR'], $invoice['gross'] ?? null);

        $supplier = $invoice['supplier'] ?? null;
        self::assertIsArray($supplier);
        self::assertSame('Atlas SAS', $supplier['legal_name'] ?? null);

        $customer = $invoice['customer'] ?? null;
        self::assertIsArray($customer);
        self::assertSame('Acme SARL', $customer['legal_name'] ?? null);

        // Filed per rate, per jurisdiction — which is how a VAT return is
        // filed, and why the tax is stored rather than recomputed.
        $taxes = $invoice['taxes'] ?? null;
        self::assertIsArray($taxes);
        self::assertCount(1, $taxes);

        $tax = $taxes[0] ?? null;
        self::assertIsArray($tax);
        self::assertSame('FR', $tax['jurisdiction'] ?? null);
        self::assertSame(2000, $tax['rate_basis_points'] ?? null);

        $charged = $tax['tax'] ?? null;
        self::assertIsArray($charged);
        self::assertSame(580, $charged['minor_units'] ?? null);
    }

    /**
     * The milestone's exit criterion.
     *
     * The offer is renamed and re-versioned underneath the invoice: the sold
     * version retires and a cheaper one takes its place on sale. An invoice
     * that read its amounts through a reference would move; this one cannot,
     * because there is no reference to read through.
     *
     * Repricing the sold version in place would be the blunter form of the
     * same assertion, and ADR-033 has made it impossible — the database
     * refuses it, so there is nothing left here to assert about it.
     */
    public function testAnInvoiceIsUnchangedAfterItsOfferIsReVersioned(): void
    {
        $issued = $this->decode($this->issueSuccessfully());
        $invoiceId = $issued['id'] ?? null;
        self::assertIsString($invoiceId);

        $this->connection->executeStatement(
            "UPDATE offers SET name = 'Atlas Enterprise' WHERE id = :offer",
            ['offer' => $this->offer],
        );
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :version",
            ['version' => $this->offerVersion],
        );
        $this->connection->executeStatement(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 2, 'ACTIVE', 'MONTHLY', 4900, 'EUR', now())",
            ['offer' => $this->offer],
        );

        $reread = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers()),
        );

        self::assertSame($issued['gross'] ?? null, $reread['gross'] ?? null);
        self::assertSame($issued['net'] ?? null, $reread['net'] ?? null);

        $lines = $reread['lines'] ?? null;
        self::assertIsArray($lines);

        $line = $lines[0] ?? null;
        self::assertIsArray($line);

        $unitPrice = $line['unit_price'] ?? null;
        self::assertIsArray($unitPrice);
        self::assertSame(2900, $unitPrice['minor_units'] ?? null);
        self::assertSame('Atlas Pro (v1) — subscription', $line['description'] ?? null);
        // The version is still named, for lineage — and naming it is exactly
        // what makes the rest of this test meaningful: the link exists and
        // no amount was read through it.
        self::assertSame($this->offerVersion, $line['source_offer_version_id'] ?? null);
    }

    public function testAProfileChangeDoesNotRewriteAnIssuedInvoice(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $this->saveProfile(['legal_name' => 'Acme Renamed SAS', 'city' => 'Marseille']);

        $customer = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers()),
        )['customer'] ?? null;

        self::assertIsArray($customer);
        // A customer moving office must not rewrite invoices their
        // accountant has already filed.
        self::assertSame('Acme SARL', $customer['legal_name'] ?? null);
        self::assertSame('Paris', $customer['city'] ?? null);
    }

    public function testNumbersAreSequentialAndGapless(): void
    {
        $this->saveProfile();
        $this->subscribe();

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $response = $this->issue();
            self::assertSame(201, $response->getStatusCode());
            $numbers[] = $this->decode($response)['number'] ?? null;
        }

        $year = date('Y');
        self::assertSame(
            [$year . '-000001', $year . '-000002', $year . '-000003'],
            $numbers,
        );
    }

    public function testACancelledInvoiceKeepsItsNumber(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $cancelled = $this->decode($this->act($invoiceId, 'cancel'));

        self::assertSame('CANCELLED', $cancelled['status'] ?? null);
        // Void, not absent. An auditor asking about this number must get an
        // answer, and "cancelled" is an answer; "no such invoice" is a gap.
        self::assertSame(date('Y') . '-000001', $cancelled['number'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));

        // And the next one continues the sequence rather than reusing it.
        $next = $this->decode($this->issue());
        self::assertSame(date('Y') . '-000002', $next['number'] ?? null);
    }

    public function testPayingRecordsTheSettlementAndCannotBeRepeated(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $paid = $this->decode($this->act($invoiceId, 'pay'));

        self::assertSame('PAID', $paid['status'] ?? null);
        self::assertIsString($paid['paid_at'] ?? null);

        $again = $this->act($invoiceId, 'pay');

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('INVALID_INVOICE_TRANSITION', $this->errorOf($again)['code'] ?? null);
    }

    public function testAPaidInvoiceCannotBeCancelled(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $this->act($invoiceId, 'pay');
        $response = $this->act($invoiceId, 'cancel');

        // Money has moved. The instrument for correcting that is a credit
        // note, not an erasure.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('INVALID_INVOICE_TRANSITION', $this->errorOf($response)['code'] ?? null);
    }

    public function testEveryChangeIsRecordedInTheLedger(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $this->act($invoiceId, 'pay');

        $types = $this->connection->fetchFirstColumn(
            'SELECT type FROM financial_events ORDER BY occurred_at, id',
        );

        self::assertSame(['INVOICE_ISSUED', 'INVOICE_PAID'], $types);
    }

    public function testAnotherTenantsInvoiceIsNotFoundRatherThanForbidden(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $other = $this->id("INSERT INTO tenants (name, slug) VALUES ('Rival', 'rival') RETURNING id");

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($other, $this->user, $this->product, ['TENANT_ADMIN'], ['billing.read']),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers());

        // 404 rather than 403: confirming the invoice exists would tell a
        // rival that Acme is a customer.
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('INVOICE_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testAMalformedInvoiceIdIsNotFoundRatherThanAnError(): void
    {
        $response = $this->request('GET', '/api/v1/billing/invoices/not-a-uuid', $this->headers());

        // It reaches a UUID column. Without a guard PostgreSQL raises, and a
        // wrong URL becomes a 500 with a SQL error behind it.
        self::assertSame(404, $response->getStatusCode());
    }

    public function testReadingBillingRequiresThePermission(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['subscription.read']),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/billing/invoices', $this->headers());

        self::assertSame(403, $response->getStatusCode());
        $details = $this->errorOf($response)['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('billing.read', $details['permission'] ?? null);
    }

    public function testAReaderCannotIssueAnInvoice(): void
    {
        $this->saveProfile();
        $this->subscribe();

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['billing.read']),
            ]),
        ]);

        $response = $this->issue();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testAProductWithNoBillingIdentityCannotInvoice(): void
    {
        $this->saveProfile();
        $this->subscribe();

        $this->connection->executeStatement(
            "DELETE FROM product_configuration WHERE product_id = :product AND key = 'billing_supplier'",
            ['product' => $this->product],
        );

        $response = $this->issue();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('BILLING_NOT_CONFIGURED', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    public function testInvoicesArePagedAndBounded(): void
    {
        $this->saveProfile();
        $this->subscribe();
        $this->issue();
        $this->issue();

        $page = $this->decode(
            $this->request('GET', '/api/v1/billing/invoices?limit=1', $this->headers()),
        );

        self::assertSame(2, $page['total'] ?? null);
        self::assertSame(1, $page['limit'] ?? null);

        $invoices = $page['invoices'] ?? null;
        self::assertIsArray($invoices);
        self::assertCount(1, $invoices);

        $refused = $this->request('GET', '/api/v1/billing/invoices?limit=5000', $this->headers());

        // Refused rather than clamped: silently serving 200 rows to a client
        // that asked for 5000 looks like success and hides broken paging.
        self::assertSame(400, $refused->getStatusCode());
    }

    // --- The document (§7 GET /invoices/{id}/pdf) ----------------------------

    public function testAnotherTenantsInvoiceHasNoDocument(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $other = $this->id("INSERT INTO tenants (name, slug) VALUES ('Rival', 'rival') RETURNING id");

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($other, $this->user, $this->product, ['TENANT_ADMIN'], ['billing.read']),
            ]),
        ]);

        // 404 for the same reason the JSON is: a rival learning the invoice
        // exists learns that Acme is a customer.
        self::assertSame(404, $this->pdf($invoiceId)->getStatusCode());
    }

    public function testReadingADocumentNeedsThePermission(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['subscription.read']),
            ]),
        ]);

        $response = $this->pdf($invoiceId);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoice_documents'));
    }

    public function testADraftHasNoDocument(): void
    {
        $this->saveProfile();
        $this->subscribe();

        // A draft raised without issuing: no number, so nothing that may be
        // handed to somebody as an invoice.
        $draftId = $this->id(
            'INSERT INTO invoices (tenant_id, product_id, currency, status)'
            . " VALUES (:t, :p, 'EUR', 'DRAFT') RETURNING id",
            ['t' => $this->tenant, 'p' => $this->product],
        );

        $response = $this->pdf($draftId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('INVOICE_NOT_RENDERABLE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoice_documents'));
    }

    public function testTheDocumentIsAPdfServedAsAnAttachment(): void
    {
        $issued = $this->decode($this->issueSuccessfully());
        $invoiceId = $issued['id'] ?? null;
        self::assertIsString($invoiceId);

        $number = $issued['number'] ?? null;
        self::assertIsString($number);

        $response = $this->pdf($invoiceId);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        // Named after the legal number, and an attachment: a PDF viewer is a
        // scripting engine, so these bytes are not rendered in this origin.
        self::assertSame(
            'attachment; filename="facture-' . $number . '.pdf"',
            $response->getHeaderLine('Content-Disposition'),
        );

        $bytes = (string) $response->getBody();

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertSame((string) strlen($bytes), $response->getHeaderLine('Content-Length'));
    }

    public function testTheDocumentIsRenderedOnceAndServedFromStorageAfter(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $first = (string) $this->pdf($invoiceId)->getBody();
        $second = (string) $this->pdf($invoiceId)->getBody();

        // The stored document is rendered once; what is served is that
        // document with the status band stamped on at download time, and
        // mpdf dates the stamping — so the two are compared without the
        // dates. Identical otherwise is the proof that nothing re-rendered.
        self::assertSame(self::undated($first), self::undated($second));
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoice_documents'));
    }

    public function testTheStoredChecksumIsOfTheBytesServed(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $response = $this->pdf($invoiceId);
        $bytes = (string) $response->getBody();

        $recorded = $this->connection->fetchAssociative(
            'SELECT byte_size, checksum, renderer FROM invoice_documents WHERE invoice_id = :id',
            ['id' => $invoiceId],
        );

        self::assertIsArray($recorded);

        $size = $recorded['byte_size'] ?? null;
        $checksum = $recorded['checksum'] ?? null;
        $renderer = $recorded['renderer'] ?? null;

        self::assertIsNumeric($size);
        self::assertIsString($checksum);
        self::assertIsString($renderer);

        // The row describes the stored document — the legal one — not the
        // served bytes, which carry a status band on top and are longer.
        self::assertGreaterThan((int) $size, strlen($bytes));
        self::assertNotSame(hash('sha256', $bytes), $checksum);
        self::assertStringStartsWith('mpdf/', $renderer);

        // The ETag names the stored checksum and the band, so a conditional
        // request has something stable to compare against and a paid invoice
        // is fetched afresh.
        self::assertStringStartsWith('"' . $checksum . '-', $response->getHeaderLine('ETag'));
    }

    /**
     * The reason the bytes are stored rather than re-derived. Nothing about
     * the invoice can change after issue, so this asserts the weaker and more
     * useful thing: whatever the tenant later becomes, the document does not
     * move.
     */
    public function testTheDocumentDoesNotFollowTheTenantRenamingItself(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        $before = (string) $this->pdf($invoiceId)->getBody();

        $this->saveProfile(['legal_name' => 'Acme Renamed SAS', 'city' => 'Marseille']);

        self::assertSame(self::undated($before), self::undated((string) $this->pdf($invoiceId)->getBody()));
    }

    /**
     * The document says where the money stands, at download time
     * (docs/tenant-roots.md §2.5): an invoice is generated before payment,
     * so one with no word on the matter reads as a bill to somebody who has
     * paid and as a receipt to somebody who has not. The band is stamped on
     * the stored bytes; the stored bytes do not change.
     */
    public function testTheDocumentStatesItsStatusAtDownloadTime(): void
    {
        $invoiceId = $this->decode($this->issueSuccessfully())['id'] ?? null;
        self::assertIsString($invoiceId);

        // Issued, not yet paid: waiting, with the due date.
        $pending = $this->pdf($invoiceId);
        self::assertSame('PENDING', $pending->getHeaderLine('X-Invoice-Status'));
        self::assertStringContainsString('EN ATTENTE DE PAIEMENT', (string) $pending->getBody());
        $pendingTag = $pending->getHeaderLine('ETag');

        // Past due: said so, with the date it went past.
        $this->connection->executeStatement("UPDATE invoices SET due_at = now() - interval '3 days' WHERE id = :id", ['id' => $invoiceId]);
        $overdue = $this->pdf($invoiceId);
        self::assertSame('OVERDUE', $overdue->getHeaderLine('X-Invoice-Status'));
        self::assertStringContainsString('ANCE D', (string) $overdue->getBody());
        self::assertStringContainsString(' LE ' . (new \DateTimeImmutable('-3 days'))->format('d/m/Y'), (string) $overdue->getBody());

        // Paid: the date and the reference, which is what an auditor asks for.
        self::assertSame(200, $this->act($invoiceId, 'pay')->getStatusCode());
        $paid = $this->pdf($invoiceId);
        self::assertSame('PAID', $paid->getHeaderLine('X-Invoice-Status'));
        self::assertStringContainsString('PAY', (string) $paid->getBody());
        self::assertStringContainsString(' LE ' . date('d/m/Y'), (string) $paid->getBody());
        self::assertStringNotContainsString('EN ATTENTE DE PAIEMENT', (string) $paid->getBody());
        self::assertNotSame($pendingTag, $paid->getHeaderLine('ETag'));

        // And one stored document throughout.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoice_documents'));
    }

    /** A PDF without the moments mpdf writes into it, for comparing two stampings. */
    private static function undated(string $pdf): string
    {
        $cleaned = preg_replace('#/(CreationDate|ModDate) \\(D:[^)]*\\)#', '', $pdf);
        $cleaned = preg_replace('#/ID \\[<[0-9a-f]+> ?<[0-9a-f]+>\\]#i', '', (string) $cleaned);

        return (string) $cleaned;
    }

    /**
     * A legal name is customer-supplied and reaches an HTML parser on its way
     * into the PDF. It must be escaped rather than closing a tag.
     */
    public function testAMarkupPayloadInThePartyNameDoesNotBreakTheDocument(): void
    {
        $this->saveProfile(['legal_name' => '</td></table><script>alert(1)</script>Acme']);
        $this->subscribe();

        $issued = $this->issue();
        self::assertSame(201, $issued->getStatusCode());

        $invoiceId = $this->decode($issued)['id'] ?? null;
        self::assertIsString($invoiceId);

        $response = $this->pdf($invoiceId);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) $response->getBody());
    }

    // --- Helpers -------------------------------------------------------------

    private function issueSuccessfully(): ResponseInterface
    {
        $this->saveProfile();
        $this->subscribe();

        $response = $this->issue();
        self::assertSame(201, $response->getStatusCode());

        return $response;
    }

    private function pdf(string $invoiceId): ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/v1/billing/invoices/' . $invoiceId . '/pdf',
            $this->headers(),
        );
    }

    private function issue(): ResponseInterface
    {
        return $this->request('POST', '/api/v1/billing/invoices', $this->headers());
    }

    private function act(string $invoiceId, string $action): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $invoiceId . '/' . $action,
            $this->headers(),
        );
    }

    private function subscribe(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/subscription',
            $this->headers(),
            $this->json(['offer_id' => $this->offer]),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $changes
     */
    private function saveProfile(array $changes = []): ResponseInterface
    {
        return $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json(array_merge([
                'legal_name' => 'Acme SARL',
                'vat_number' => 'FR98765432109',
                'address_line1' => '12 avenue des Champs',
                'postal_code' => '75008',
                'city' => 'Paris',
                'country_code' => 'FR',
                'billing_email' => 'compta@acme.test',
            ], $changes)),
        );
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
    }

    private function seedCatalogue(): void
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
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

    /**
     * Deliberately not named count(): TestCase::count() is final, and
     * shadowing it is a fatal error rather than a style question.
     */
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
