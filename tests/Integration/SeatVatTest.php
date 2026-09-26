<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
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
 * A seat is taxed by whoever sells it (2026-09-26).
 *
 * ADR-055 made the organisation the supplier of a seat, and ADR-054 gave it
 * its own numbering series — and for one day the tax engine went on deciding
 * the regime between the *product's* supplier settings and the tenant's
 * customer profile. The document said Acme → Ada while the regime said
 * platform → Acme, which is not a detail: it changes the rate, the country
 * of taxation and whose VAT return the fact lands in.
 *
 * The fixture is built to make the difference visible rather than plausible:
 *
 * ```text
 * the platform   IE, registered for the one-stop shop
 * Acme           FR, a taxable person with a verified VAT number
 * Ada            a member of Acme, buying a seat for herself
 * ```
 *
 * Decided the old way, that is an intra-Community B2B supply to a verified
 * number — `REVERSE_CHARGE`, 0%, taxed in FR, filed by the platform. Decided
 * between the parties the invoice actually names, it is a French company
 * selling to a French consumer: domestic, 20%, filed by Acme.
 */
#[CoversNothing]
final class SeatVatTest extends DatabaseApiTestCase
{
    /** 29.00 EUR, the offer's price throughout. */
    private const PRICE = 2_900;

    private string $product = '';
    private string $tenant = '';
    private string $ada = '';
    private string $offer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->ada = $this->id(
            "INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-ada', 'ada@acme.test', 'Ada Lovelace') RETURNING id",
        );

        $this->configureAnIrishPlatform();
        $this->seedCatalogue();

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->ada,
                    $this->product,
                    ['TENANT_ADMIN'],
                    [
                        'tax.read', 'tax.manage',
                        'billing.read', 'billing.manage', 'billing.pay',
                        'sales.read', 'sales.manage',
                        'subscription.read', 'subscription.manage',
                    ],
                ),
            ]),
        ]);

        $this->saveBillingProfile();
    }

    /**
     * The finding, reproduced and closed.
     *
     * Acme's VAT number is verified and the platform is in another member
     * state, which is exactly the shape that used to produce reverse charge.
     * It must not here: Acme is the one selling.
     */
    public function testASeatIsTaxedWhereTheOrganisationSellsItAndNotWhereThePlatformIs(): void
    {
        $this->saveTaxProfile([
            'customer_kind' => 'B2B',
            'country_code' => 'FR',
            'taxable_person' => true,
            // The stub validator answers by the last digit: anything but 0 or
            // 9 verifies. A *verified* number is what reverse charge needs,
            // so this is the number that used to zero the invoice.
            'vat_number' => 'FR12345678901',
        ]);

        $invoice = $this->buyASeat();

        self::assertSame(2_000, $this->rateOf($invoice), 'The French domestic rate, not the Irish one and not zero.');
        self::assertSame(580, self::amount($invoice, 'vat'));
        self::assertSame(self::PRICE + 580, self::amount($invoice, 'gross'));

        $fact = $this->factOf($invoice);

        self::assertSame('STANDARD', $fact['vat_regime']);
        self::assertSame('domestic.standard', $fact['rule_id']);
        self::assertSame('FR', $fact['country']);
        self::assertSame(580, $fact['vat_amount']);
    }

    /**
     * And the fact belongs to Acme's return, not the platform's.
     *
     * The report query filters on jurisdiction alone, so before today a
     * French deployment would have declared — and paid — the VAT every one of
     * its customers charged their own staff.
     */
    public function testTheOrganisationsVatIsNotInThePlatformsReturn(): void
    {
        $this->saveTaxProfile(['customer_kind' => 'B2B', 'country_code' => 'FR', 'taxable_person' => true]);

        $invoice = $this->buyASeat();
        $fact = $this->factOf($invoice);

        self::assertSame($this->tenant, $fact['issuer_tenant_id'], 'Acme charged it, so Acme owes it.');

        $totals = $this->platformTotals('FR');

        self::assertSame(0, $totals['total_vat'] ?? null);
        self::assertSame(0, $totals['transaction_count'] ?? null);
    }

    /**
     * A company below the threshold charges no VAT, and the document says
     * why.
     *
     * Not `OUT_OF_SCOPE`, which is what a sale outside the Union is: the
     * supply is in scope and exempt, and a return that merged the two would
     * have one line where it needs two.
     */
    public function testAnOrganisationThatIsNotATaxablePersonChargesNoVat(): void
    {
        $this->saveTaxProfile(['customer_kind' => 'B2C', 'country_code' => 'FR', 'taxable_person' => false]);

        $invoice = $this->buyASeat();

        self::assertSame(0, $this->rateOf($invoice));
        self::assertSame(0, self::amount($invoice, 'vat'));
        self::assertSame(self::PRICE, self::amount($invoice, 'gross'));

        $fact = $this->factOf($invoice);

        self::assertSame('EXEMPT', $fact['vat_regime']);
        self::assertSame('supplier.not_registered', $fact['rule_id']);
    }

    /**
     * An organisation that has never opened the tax screen sells with VAT.
     *
     * The storefront's whole point is that a stranger signs up and buys in
     * the same minute (ADR-041), and there is no moment in that minute to
     * state a fiscal position. Charging is the ordinary case and the
     * recoverable direction: VAT wrongly charged is undone by a credit note,
     * VAT wrongly not charged is a debt found at the declaration.
     */
    public function testAnOrganisationThatHasStatedNothingSellsWithVat(): void
    {
        $invoice = $this->buyASeat();

        self::assertSame(2_000, $this->rateOf($invoice));
        self::assertSame('STANDARD', $this->factOf($invoice)['vat_regime']);
    }

    /**
     * Crediting an invoice reverses the fiscal fact it wrote.
     *
     * Nothing did this until today. `vat_transactions.credit_note_id` was in
     * the first fiscal migration and `VatTransaction`'s own docblock said a
     * correction is a transaction attached to a credit note — and no code
     * ever wrote one. A fully credited invoice went on being declared, and
     * closing the period froze the figure permanently.
     */
    public function testCreditingAnInvoiceReversesItsVat(): void
    {
        $this->saveTaxProfile(['customer_kind' => 'B2B', 'country_code' => 'FR', 'taxable_person' => true]);

        $invoice = $this->buyASeat();
        $invoiceId = $invoice['id'];
        self::assertIsString($invoiceId);

        $response = $this->request(
            'POST',
            '/api/v1/billing/invoices/' . $invoiceId . '/credit',
            $this->headers(),
            $this->json(['reason' => 'Issued in error.']),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $note = $this->decode($response);
        $noteId = $note['id'] ?? null;
        self::assertIsString($noteId);

        $reversal = $this->connection->fetchAssociative(
            'SELECT taxable_base, vat_amount, vat_rate, vat_regime, rule_id, country, issuer_tenant_id
               FROM vat_transactions WHERE credit_note_id = :note',
            ['note' => $noteId],
        );

        self::assertIsArray($reversal, 'A credit note writes its reversing fact.');
        self::assertSame(-self::PRICE, Row::integer($reversal, 'taxable_base'));
        self::assertSame(-580, Row::integer($reversal, 'vat_amount'));
        // Copied, never recomputed: the rate and the rule are the ones that
        // applied when the sale happened (§25.3).
        self::assertSame(2_000, Row::integer($reversal, 'vat_rate'));
        self::assertSame('STANDARD', Row::string($reversal, 'vat_regime'));
        self::assertSame('domestic.standard', Row::string($reversal, 'rule_id'));
        self::assertSame('FR', Row::string($reversal, 'country'));
        self::assertSame($this->tenant, Row::nullableString($reversal, 'issuer_tenant_id'));

        // And the two sum to nothing, which is the point: the organisation
        // declares no VAT on a sale it undid.
        $net = $this->connection->fetchOne(
            'SELECT coalesce(sum(vat_amount), 0) FROM vat_transactions WHERE tenant_id = :tenant',
            ['tenant' => $this->tenant],
        );

        self::assertSame(0, is_numeric($net) ? (int) $net : null);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * The whole chain a customer walks: order, invoice, and the invoice read
     * back as the customer receives it.
     *
     * @return array<string, mixed>
     */
    private function buyASeat(): array
    {
        $order = $this->request(
            'POST',
            '/api/v1/sales/orders',
            $this->headers(),
            $this->json(['offer_id' => $this->offer]),
        );

        self::assertSame(201, $order->getStatusCode(), (string) $order->getBody());

        $orderId = $this->decode($order)['id'] ?? null;
        self::assertIsString($orderId);

        $fulfilled = $this->request('POST', '/api/v1/sales/orders/' . $orderId . '/fulfil', $this->headers());
        self::assertSame(200, $fulfilled->getStatusCode(), (string) $fulfilled->getBody());

        $invoiceId = $this->decode($fulfilled)['invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        $read = $this->request('GET', '/api/v1/billing/invoices/' . $invoiceId, $this->headers());
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());

        /** @var array<string, mixed> $invoice */
        $invoice = $this->decode($read);

        // The document names the organisation and the person, which is what
        // makes the regime question the one this file is about.
        self::assertSame('Acme SARL', self::named($invoice, 'supplier'));
        self::assertSame('Ada Lovelace', self::named($invoice, 'customer'));

        return $invoice;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function rateOf(array $invoice): int
    {
        $lines = $invoice['lines'] ?? null;
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $line = $lines[0] ?? null;
        self::assertIsArray($line);

        $rate = $line['vat_rate_basis_points'] ?? null;
        self::assertIsInt($rate);

        return $rate;
    }

    /**
     * The one fiscal fact the invoice wrote.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private function factOf(array $invoice): array
    {
        $fact = $this->connection->fetchAssociative(
            'SELECT vat_regime, rule_id, country, vat_amount, taxable_base, issuer_tenant_id
               FROM vat_transactions WHERE invoice_id = :invoice',
            ['invoice' => $invoice['id']],
        );

        self::assertIsArray($fact, 'The invoice wrote a fiscal fact.');

        return [
            'vat_regime' => Row::string($fact, 'vat_regime'),
            'rule_id' => Row::string($fact, 'rule_id'),
            'country' => Row::string($fact, 'country'),
            'vat_amount' => Row::integer($fact, 'vat_amount'),
            'taxable_base' => Row::integer($fact, 'taxable_base'),
            'issuer_tenant_id' => Row::nullableString($fact, 'issuer_tenant_id'),
        ];
    }

    /**
     * What the platform would declare for a jurisdiction over a period
     * covering everything written in this test.
     *
     * @return array<string, mixed>
     */
    private function platformTotals(string $jurisdiction): array
    {
        $period = $this->id(
            'INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)'
            . " VALUES (:jurisdiction, 'MONTHLY', current_date - 1, current_date + 1) RETURNING id",
            ['jurisdiction' => $jurisdiction],
        );

        $response = $this->request('GET', '/api/v1/tax/reports/' . $period, $this->headers());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $totals = $this->decode($response)['totals'] ?? null;
        self::assertIsArray($totals);

        /** @var array<string, mixed> $totals */
        return $totals;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function saveTaxProfile(array $changes): ResponseInterface
    {
        $response = $this->request(
            'PUT',
            '/api/v1/tax/profile',
            $this->headers(),
            $this->json($changes),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    private function saveBillingProfile(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json([
                'legal_name' => 'Acme SARL',
                'address_line1' => '12 avenue des Champs',
                'postal_code' => '75008',
                'city' => 'Paris',
                'country_code' => 'FR',
                'billing_email' => 'compta@acme.test',
            ]),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * One of the document's money fields, in minor units.
     *
     * @param array<string, mixed> $invoice
     */
    private static function amount(array $invoice, string $field): int
    {
        $money = $invoice[$field] ?? null;
        self::assertIsArray($money);

        $minorUnits = $money['minor_units'] ?? null;
        self::assertIsInt($minorUnits);

        return $minorUnits;
    }

    /**
     * A party block's legal name.
     *
     * @param array<string, mixed> $invoice
     */
    private static function named(array $invoice, string $party): string
    {
        $block = $invoice[$party] ?? null;
        self::assertIsArray($block);

        $name = $block['legal_name'] ?? null;
        self::assertIsString($name);

        return $name;
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
        return ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'];
    }

    /**
     * A platform in another member state, registered for the one-stop shop —
     * so that any sale it made to a French consumer would be Irish-decided
     * and OSS-taxed. Nothing about a seat may read any of it.
     */
    private function configureAnIrishPlatform(): void
    {
        $supplier = json_encode([
            'legal_name' => 'Atlas Software Ltd',
            'vat_number' => 'IE1234567T',
            'registration_number' => 'IE-123456',
            'address_line1' => '1 Grand Canal Square',
            'postal_code' => 'D02',
            'city' => 'Dublin',
            'country_code' => 'IE',
        ]);

        self::assertIsString($supplier);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "IE", "oss_registered": true}' AS jsonb))
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
            . " VALUES (:product, :plan, 'pro-monthly', 'Pro monthly') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $this->id(
            'INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', :price, 'EUR', now() - interval '1 day') RETURNING id",
            ['offer' => $this->offer, 'price' => self::PRICE],
        );
    }
}
