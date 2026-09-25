<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The last thing standing between a console-built product and money.
 *
 * ADR-042 gave the console a way to create a product and ADR-043 a way to price
 * it, and a product built that way could be advertised, chosen and paid for right
 * up to the moment it had to raise a document — where it refused with
 * `BILLING_NOT_CONFIGURED`, because an invoice must name its issuer and the
 * issuer lives in `product_configuration`. Exactly one thing on this platform
 * ever wrote that table: `bin/seed-demo.php`.
 *
 * The test that matters is the last one, and it is the same shape as
 * `ConsoleCatalogueTest`'s: **a product created, priced and configured entirely
 * through the console can take somebody's money and name itself correctly on the
 * invoice.** Everything above it is the rules that make that safe.
 *
 * `product_configuration` is never written by SQL here — writing the fixture the
 * way the old tests had to would test nothing about the gap being closed.
 */
#[CoversNothing]
final class ConsoleConfigurationTest extends DatabaseApiTestCase
{
    private const SECRET = 'configuration-test-secret';

    /**
     * @var array<string, string>
     */
    private const ISSUER = [
        'legal_name' => 'Atlas SAS',
        'vat_number' => 'FR12345678901',
        'registration_number' => '123 456 789 00012',
        'address_line1' => '1 rue de la Paix',
        'postal_code' => '75002',
        'city' => 'Paris',
        'country_code' => 'FR',
    ];

    private string $product = '';
    private string $tenant = '';
    private string $buyer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->appoint($admin, 'PLATFORM_ADMIN');
        $this->appoint($support, 'SUPPORT_ADMIN');

        $this->buyer = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
                'alice-token' => 'sub-alice',
            ]),

            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),
        ]);

        // Created through the console, not by SQL: this is the product an
        // administrator makes on a fresh installation, and its emptiness —
        // including having no billing identity — is the situation under test.
        $created = $this->request(
            'POST',
            '/api/v1/staff/products',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'atlas', 'name' => 'Atlas']),
        );

        self::assertSame(201, $created->getStatusCode());

        $id = $this->itemIn($created, 'product')['id'] ?? null;
        self::assertIsString($id);
        $this->product = $id;
    }

    // --- What a product an administrator just made looks like ----------------

    public function testAFreshProductCannotInvoiceAndSaysWhichMentionsAreMissing(): void
    {
        $configuration = $this->decode($this->show());

        self::assertFalse($configuration['can_invoice'] ?? null);
        // The field names, not only a flag: somebody who has to fix this should
        // not be comparing a form against a specification.
        self::assertSame(['legal_name', 'country_code'], $configuration['missing'] ?? null);
    }

    public function testAnUnwrittenIdentityStillCarriesEveryFieldAsNull(): void
    {
        $supplier = $this->itemIn($this->show(), 'billing_supplier');

        self::assertArrayHasKey('vat_number', $supplier);
        self::assertNull($supplier['vat_number']);
        self::assertNull($supplier['legal_name']);
    }

    public function testTheTaxJurisdictionFallsBackToTheIssuersCountry(): void
    {
        $this->setIssuer(self::ISSUER);

        // The tax key has never been written, and the regime still has to be
        // knowable: an invoice is issued under the supplier's own VAT, so the
        // supplier's country is the only honest default.
        self::assertSame('FR', $this->itemIn($this->show(), 'tax')['country'] ?? null);
    }

    // --- Who may do this -----------------------------------------------------

    public function testOnlyProductAdministratorsMayReadOrWriteTheConfiguration(): void
    {
        // Support reaches every other /staff read. Who the platform invoices as
        // is not support's, and neither is seeing it.
        self::assertSame(403, $this->show('sam-token')->getStatusCode());
        self::assertSame(403, $this->setIssuer(self::ISSUER, 'sam-token', 403)->getStatusCode());
    }

    public function testAnUnknownProductIsANotFoundAndNotAServerError(): void
    {
        // The audit trail's `product_id` is a foreign key to `products`, so a
        // row written for a product that does not exist would fail the insert
        // and turn this 404 into a 500 — which is what happened the first time
        // a test asked the products desk for a product that was not there.
        $response = $this->request(
            'GET',
            '/api/v1/staff/configuration?product=nope',
            ['Authorization' => 'Bearer ola-token'],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PRODUCT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testTheProductMustBeNamed(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/staff/configuration',
            ['Authorization' => 'Bearer ola-token'],
        );

        // Refused rather than defaulted to whichever product came first: a
        // console that silently configured one would eventually invoice as the
        // wrong company.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    // --- The issuer ----------------------------------------------------------

    public function testSettingTheIssuerMakesTheProductAbleToInvoice(): void
    {
        $response = $this->setIssuer(self::ISSUER);

        self::assertSame('Atlas SAS', $this->itemIn($response, 'billing_supplier')['legal_name'] ?? null);

        $configuration = $this->decode($this->show());

        self::assertTrue($configuration['can_invoice'] ?? null);
        self::assertSame([], $configuration['missing'] ?? null);
    }

    public function testAnIncompleteIdentityIsRefusedRatherThanStored(): void
    {
        $partial = self::ISSUER;
        unset($partial['legal_name']);

        $response = $this->setIssuer($partial, 'ola-token', 400);

        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
        self::assertSame(['missing' => ['legal_name']], $this->errorOf($response)['details'] ?? null);

        // And nothing was written — not the fields that *were* sent either.
        // Storing a half identity while answering 200 is how a broken form looks
        // like a working one, and the failure would surface later, to a
        // customer, at the checkout.
        $stored = $this->itemIn($this->show(), 'billing_supplier');

        self::assertNull($stored['city']);
        self::assertNull($stored['vat_number']);
        self::assertNull($stored['country_code']);
    }

    public function testACountryThatIsNotACountryCodeIsRefused(): void
    {
        $wrong = self::ISSUER;
        $wrong['country_code'] = 'France';

        $response = $this->setIssuer($wrong, 'ola-token', 400);

        self::assertSame(['missing' => ['country_code']], $this->errorOf($response)['details'] ?? null);
    }

    public function testTheCountryIsUpperCasedSoOneJurisdictionIsOneJurisdiction(): void
    {
        $lower = self::ISSUER;
        $lower['country_code'] = 'fr';

        self::assertSame('FR', $this->itemIn($this->setIssuer($lower), 'billing_supplier')['country_code'] ?? null);
    }

    public function testAFieldIsClearedByBeingSentBlankAndNotByBeingOmitted(): void
    {
        $this->setIssuer(self::ISSUER);

        $withoutVat = self::ISSUER;
        $withoutVat['vat_number'] = '';

        $supplier = $this->itemIn($this->setIssuer($withoutVat), 'billing_supplier');

        // A supplier that stops being liable for VAT has to be able to remove
        // its number, which is the whole reason this endpoint is a PUT.
        self::assertNull($supplier['vat_number']);
        self::assertSame('Atlas SAS', $supplier['legal_name']);
    }

    public function testThePutReplacesTheDocumentRatherThanMergingIntoIt(): void
    {
        $this->setIssuer(self::ISSUER);

        // The second call does not mention the city at all. Under a merge it
        // would survive, and an address nobody re-entered would keep printing
        // on invoices after the company moved.
        $moved = ['legal_name' => 'Atlas SAS', 'country_code' => 'FR'];

        $supplier = $this->itemIn($this->setIssuer($moved), 'billing_supplier');

        self::assertNull($supplier['city']);
        self::assertNull($supplier['vat_number']);
    }

    // --- The tax position ----------------------------------------------------

    public function testTheTaxPositionIsStoredAndReadBack(): void
    {
        $response = $this->setTax([
            'country' => 'be',
            'oss_registered' => true,
            'supply_type' => 'DIGITAL_SERVICES',
            'currency' => 'eur',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $tax = $this->itemIn($this->show(), 'tax');

        self::assertSame('BE', $tax['country'] ?? null);
        self::assertSame('EUR', $tax['currency'] ?? null);
        self::assertTrue($tax['oss_registered'] ?? null);
        self::assertSame('DIGITAL_SERVICES', $tax['supply_type'] ?? null);
    }

    public function testAnUnknownSupplyTypeIsRefusedRatherThanDefaulted(): void
    {
        $response = $this->setTax([
            'country' => 'FR',
            'oss_registered' => false,
            'supply_type' => 'CONSULTING',
            'currency' => 'EUR',
        ], 400);

        // What is being supplied decides where a sale is taxed, so a value
        // nobody recognised must not quietly become digital services.
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
        self::assertSame('supply_type', $this->refusedField($response));
    }

    public function testTheOssFlagIsRequiredBecauseOmittingItWouldSwitchTheRegimeOff(): void
    {
        $response = $this->setTax([
            'country' => 'FR',
            'supply_type' => 'DIGITAL_SERVICES',
            'currency' => 'EUR',
        ], 400);

        self::assertSame('oss_registered', $this->refusedField($response));
    }

    // --- The trail -----------------------------------------------------------

    public function testWritesAreRecordedAndReadsAreNot(): void
    {
        $this->show();
        $this->show();

        // A price list and a fiscal identity are the platform's own, so looking
        // at them crosses no tenant boundary (non-negotiable #21). A row per
        // look would bury the decisions among them.
        self::assertSame([], $this->configurationActions());

        $this->setIssuer(self::ISSUER);
        $this->setTax([
            'country' => 'FR',
            'oss_registered' => true,
            'supply_type' => 'DIGITAL_SERVICES',
            'currency' => 'EUR',
        ]);

        self::assertSame(['CONFIGURE_BILLING', 'CONFIGURE_TAX'], $this->configurationActions());
    }

    public function testTheTrailNamesWhoTheProductNowInvoicesAs(): void
    {
        $this->setIssuer(self::ISSUER);

        $detail = $this->connection->fetchOne(
            "SELECT detail::text FROM staff_access_log WHERE action = 'CONFIGURE_BILLING'",
        );

        self::assertIsString($detail);

        $decoded = json_decode($detail, true);

        self::assertIsArray($decoded);
        // "Why does the January batch name a different issuer?" is answerable
        // only from a trail that recorded the name.
        self::assertSame('Atlas SAS', $decoded['legal_name'] ?? null);
        self::assertSame('FR', $decoded['country_code'] ?? null);
    }

    public function testARefusedWriteLeavesNoRowClaimingItHappened(): void
    {
        $partial = self::ISSUER;
        unset($partial['country_code']);

        $this->setIssuer($partial, 'ola-token', 400);

        self::assertSame([], $this->configurationActions());
    }

    // --- The whole point -----------------------------------------------------

    public function testAProductBuiltEntirelyInTheConsoleCanTakeMoney(): void
    {
        $offer = $this->priceTheProduct();

        // A tenant that can buy: a billing profile of its own, which is the
        // customer's identity and not the platform's.
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->buyer,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'billing.pay', 'sales.read', 'sales.manage', 'subscription.read'],
                ),
            ]),
        ]);

        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->buyerHeaders(),
            $this->json(['legal_name' => 'Acme SARL', 'country_code' => 'FR', 'city' => 'Paris']),
        )->getStatusCode());

        // Before the console wrote an issuer: everything is in place, the offer
        // is on sale, the customer can pay — and it refuses, because an invoice
        // with a blank issuer is not an invoice. This is the failure the last
        // two ADRs left behind.
        $refused = $this->checkout($offer);

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('BILLING_NOT_CONFIGURED', $this->errorOf($refused)['code'] ?? null);

        $this->setIssuer(self::ISSUER);
        $this->setTax([
            'country' => 'FR',
            'oss_registered' => true,
            'supply_type' => 'DIGITAL_SERVICES',
            'currency' => 'EUR',
        ]);

        // The same call, and nothing else changed but two console writes.
        $opened = $this->checkout($offer);

        self::assertSame(201, $opened->getStatusCode(), (string) $opened->getBody());

        $session = $this->itemIn($opened, 'session');
        $invoiceId = $session['invoice_id'] ?? null;

        self::assertIsString($invoiceId);

        // And the document names the seller, copied at the moment it was
        // raised: changing either configuration afterwards can never rewrite
        // an invoice already issued.
        //
        // The seller is **the organisation**, because what the tenant surface
        // sells is a seat — Acme selling to one of its own people
        // (2026-09-19), and since 2026-09-25 the only sale there is. So the
        // console's issuer is not on this document, and the two halves of
        // this test now say different things: the 409 above is what the
        // console's issuer still decides, and the snapshot below is who the
        // customer is actually buying from.
        //
        // That the product's identity gates a sale it never appears on is
        // worth knowing about rather than asserting away.
        $issuer = $this->connection->fetchOne(
            'SELECT supplier_snapshot::text FROM invoices WHERE id = :id',
            ['id' => $invoiceId],
        );

        self::assertIsString($issuer);

        $decoded = json_decode($issuer, true);

        self::assertIsArray($decoded);
        self::assertSame('Acme SARL', $decoded['legal_name'] ?? null);

        // Numbered in Acme's own series, which is the same fact read from the
        // other side (ADR-054).
        self::assertSame(
            $this->tenant,
            $this->connection->fetchOne('SELECT issuer_tenant_id FROM invoices WHERE id = :id', ['id' => $invoiceId]),
        );
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Plan, offer, published version — all through the console, as ADR-043's own
     * test does. Returns the offer id.
     */
    private function priceTheProduct(): string
    {
        $plan = $this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]),
        );

        self::assertSame(201, $plan->getStatusCode());

        $planId = $this->itemIn($plan, 'plan')['id'] ?? null;
        self::assertIsString($planId);

        $offer = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => 'pro-monthly',
                'name' => 'Pro, monthly',
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ]),
        );

        self::assertSame(201, $offer->getStatusCode());

        $offerId = $this->itemIn($offer, 'offer')['id'] ?? null;
        self::assertIsString($offerId);

        self::assertSame(200, $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers/' . $offerId . '/publish?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['version' => 1]),
        )->getStatusCode());

        return $offerId;
    }

    private function checkout(string $offerId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/checkout/sessions',
            $this->buyerHeaders(),
            $this->json(['offer_id' => $offerId]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function buyerHeaders(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
    }

    private function show(string $token = 'ola-token'): ResponseInterface
    {
        $response = $this->request(
            'GET',
            '/api/v1/staff/configuration?product=atlas',
            ['Authorization' => 'Bearer ' . $token],
        );

        if ($token === 'ola-token') {
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $supplier
     */
    private function setIssuer(
        array $supplier,
        string $token = 'ola-token',
        int $expected = 200,
    ): ResponseInterface {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/configuration/billing-identity?product=atlas',
            ['Authorization' => 'Bearer ' . $token],
            $this->json($supplier),
        );

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @param array<string, mixed> $tax
     */
    private function setTax(array $tax, int $expected = 200): ResponseInterface
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/configuration/tax?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json($tax),
        );

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @return list<string>
     */
    private function configurationActions(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT action FROM staff_access_log
                 WHERE resource_type = 'product_configuration'
                 ORDER BY action
                SQL,
        );

        $actions = [];

        foreach ($rows as $action) {
            self::assertIsString($action);
            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * Which field a `VALIDATION_FAILED` names.
     */
    private function refusedField(ResponseInterface $response): ?string
    {
        $details = $this->errorOf($response)['details'] ?? null;

        if (!is_array($details)) {
            return null;
        }

        $field = $details['field'] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemIn(ResponseInterface $response, string $key): array
    {
        $item = $this->decode($response)[$key] ?? null;

        self::assertIsArray($item, $key . ' missing from ' . (string) $response->getBody());

        /** @var array<string, mixed> $item */
        return $item;
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
    }

    private function id(string $sql): string
    {
        $identifier = $this->connection->fetchOne($sql);

        self::assertIsString($identifier);

        return $identifier;
    }
}
