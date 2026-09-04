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
 * The §37.4 fiscal scenarios, through the real pipeline and the real database.
 *
 * A double would defeat most of these. The rate windows are an exclusion
 * constraint, the reverse-charge rule is a CHECK constraint, and period
 * closure is a trigger — all three are exactly what an in-memory repository
 * would implement correctly by accident.
 *
 * The stub validator answers by the last digit of the number, so the
 * fail-closed paths can be exercised deliberately:
 *
 *     ...0 → VIES unreachable   ...9 → invalid   otherwise → valid
 */
#[CoversNothing]
final class FiscalChainTest extends DatabaseApiTestCase
{
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
                    [
                        'tax.read', 'tax.manage',
                        'billing.read', 'billing.manage',
                        'subscription.read', 'subscription.manage',
                    ],
                ),
            ]),
        ]);
    }

    // --- the five regimes ------------------------------------------------

    public function testADomesticConsumerPaysTheDomesticRate(): void
    {
        $this->saveTaxProfile(['country_code' => 'FR', 'customer_kind' => 'B2C']);

        $calculation = $this->calculate(10_000);

        self::assertSame('STANDARD', $calculation['regime']);
        self::assertSame('FR', $calculation['country_of_taxation']);
        self::assertSame(2000, $calculation['rate_basis_points']);
        self::assertSame(2000, $calculation['vat_amount']);
    }

    public function testAVerifiedIntraEuBusinessIsInvoicedAtZeroWithTheMention(): void
    {
        $this->saveTaxProfile([
            'country_code' => 'DE',
            'customer_kind' => 'B2B',
            'taxable_person' => true,
            'vat_number' => 'DE123456781',
        ]);

        $calculation = $this->calculate(10_000);

        self::assertSame('REVERSE_CHARGE', $calculation['regime']);
        self::assertSame(0, $calculation['vat_amount']);
        self::assertTrue($calculation['reverse_charge']);
        // The mention is a legal requirement on the document, not a nicety.
        self::assertNotNull($calculation['legal_mention']);
    }

    public function testAnUnverifiedNumberDoesNotGetReverseCharge(): void
    {
        // Ends in 9: the stub reports the number invalid.
        $this->saveTaxProfile([
            'country_code' => 'DE',
            'customer_kind' => 'B2B',
            'taxable_person' => true,
            'vat_number' => 'DE123456789',
        ]);

        $calculation = $this->calculate(10_000);

        self::assertSame('STANDARD', $calculation['regime']);
        self::assertSame(2000, $calculation['vat_amount']);
        self::assertFalse($calculation['reverse_charge']);
    }

    public function testViesBeingUnreachableGrantsNothing(): void
    {
        // Ends in 0: the stub reports the service unreachable.
        $profile = $this->decode($this->saveTaxProfile([
            'country_code' => 'DE',
            'customer_kind' => 'B2B',
            'taxable_person' => true,
            'vat_number' => 'DE123456780',
        ]))['profile'] ?? null;

        self::assertIsArray($profile);
        // "We asked and got no answer" is its own status, distinguishable
        // from "nobody asked" — and neither is a verification.
        self::assertSame('UNAVAILABLE', $profile['vat_number_status'] ?? null);
        self::assertFalse($profile['reverse_charge_available'] ?? null);

        // The sale is taxed rather than silently reclassified.
        self::assertSame('STANDARD', $this->calculate(10_000)['regime']);
    }

    public function testACrossBorderConsumerIsTaxedInTheirOwnCountry(): void
    {
        $this->saveTaxProfile(['country_code' => 'DE', 'customer_kind' => 'B2C']);

        $calculation = $this->calculate(10_000);

        self::assertSame('OSS', $calculation['regime']);
        self::assertSame('DE', $calculation['country_of_taxation']);
        // Germany's rate, not France's.
        self::assertSame(1900, $calculation['rate_basis_points']);
    }

    public function testASaleOutsideTheUnionIsOutOfScope(): void
    {
        $this->saveTaxProfile(['country_code' => 'US', 'customer_kind' => 'B2C']);

        $calculation = $this->calculate(10_000);

        self::assertSame('OUT_OF_SCOPE', $calculation['regime']);
        self::assertSame(0, $calculation['vat_amount']);
    }

    // --- the history invariants ------------------------------------------

    public function testTheClockPicksTheRateNotTheCurrentValue(): void
    {
        $this->saveTaxProfile(['country_code' => 'EE', 'customer_kind' => 'B2C']);

        // Estonia moved from 22% to 24% on 2025-07-01, and the seed carries
        // both windows. A table of current values would answer 24 for both.
        self::assertSame(2200, $this->calculate(10_000, '2025-06-30')['rate_basis_points']);
        self::assertSame(2400, $this->calculate(10_000, '2025-07-01')['rate_basis_points']);
    }

    public function testExactlyOneRateAnswersForEveryCountryToday(): void
    {
        $rates = $this->decode(
            $this->request('GET', '/api/v1/tax/rates', $this->headers()),
        )['rates'] ?? null;

        self::assertIsArray($rates);

        $perCountry = [];

        foreach ($rates as $rate) {
            self::assertIsArray($rate);

            if (($rate['rate_kind'] ?? null) === 'STANDARD') {
                $country = $rate['country_code'] ?? '';
                self::assertIsString($country);
                $perCountry[$country] = ($perCountry[$country] ?? 0) + 1;
            }
        }

        self::assertCount(27, $perCountry);
        self::assertSame([1], array_values(array_unique($perCountry)));
    }

    public function testInvoicingWritesTheFiscalFactInTheSameTransaction(): void
    {
        $this->saveTaxProfile(['country_code' => 'FR', 'customer_kind' => 'B2C']);
        $this->saveBillingProfile();
        $this->subscribe();

        $response = $this->request('POST', '/api/v1/billing/invoices', $this->headers());
        self::assertSame(201, $response->getStatusCode());

        // The invoice endpoint answers with the presenter's output directly;
        // there is no envelope around it.
        $invoice = $this->decode($response);

        $facts = $this->decode(
            $this->request('GET', '/api/v1/tax/transactions', $this->headers()),
        )['transactions'] ?? null;

        self::assertIsArray($facts);
        self::assertCount(1, $facts);

        $fact = $facts[0];
        self::assertIsArray($fact);
        self::assertSame($invoice['id'] ?? null, $fact['invoice_id'] ?? null);
        self::assertSame('STANDARD', $fact['vat_regime'] ?? null);
        self::assertSame('domestic.standard', $fact['rule_id'] ?? null);

        // §25.3: the fiscal facts of an invoice sum to that invoice's VAT.
        // The presenter renders money as {minor_units, currency}.
        $vat = $invoice['vat'] ?? null;
        self::assertIsArray($vat);
        self::assertSame($vat['minor_units'] ?? null, $fact['vat_amount'] ?? null);
    }

    public function testAReverseChargedSaleLeavesAFactThatSaysSo(): void
    {
        $this->saveTaxProfile([
            'country_code' => 'DE',
            'customer_kind' => 'B2B',
            'taxable_person' => true,
            'vat_number' => 'DE123456781',
        ]);
        $this->saveBillingProfile(['country_code' => 'DE']);
        $this->subscribe();

        self::assertSame(
            201,
            $this->request('POST', '/api/v1/billing/invoices', $this->headers())->getStatusCode(),
        );

        $fact = $this->firstFact();
        self::assertSame('REVERSE_CHARGE', $fact['vat_regime'] ?? null);
        self::assertTrue($fact['reverse_charge'] ?? null);
        self::assertSame(0, $fact['vat_amount'] ?? null);
        // The database refuses reverse charge on anything but a verified
        // number, so this row existing is itself the proof.
        self::assertSame('VERIFIED', $fact['customer_tax_status'] ?? null);
    }

    public function testChangingARateMovesNoVatAlreadyInvoiced(): void
    {
        $this->saveTaxProfile(['country_code' => 'FR', 'customer_kind' => 'B2C']);
        $this->saveBillingProfile();
        $this->subscribe();
        $this->request('POST', '/api/v1/billing/invoices', $this->headers());

        $before = $this->firstFact();

        // The law changes: close the current window, open a new one. This is
        // the only correct way to move a rate, and the exclusion constraint
        // refuses any other.
        $this->connection->executeStatement(
            "UPDATE tax_rates SET valid_until = now() WHERE country_code = 'FR' AND rate_kind = 'STANDARD'",
        );
        $this->connection->executeStatement(
            'INSERT INTO tax_rates (country_code, rate_kind, basis_points, valid_from)'
            . " VALUES ('FR', 'STANDARD', 2500, now())",
        );

        $after = $this->firstFact();
        // The rate is stored as a value, never as a key to a row that moves.
        self::assertSame($before['vat_rate'], $after['vat_rate']);
        self::assertSame($before['vat_amount'], $after['vat_amount']);

        // And the new rate governs what happens next.
        self::assertSame(2500, $this->calculate(10_000)['rate_basis_points']);
    }

    // --- period closure ---------------------------------------------------

    public function testAClosedPeriodRefusesToBeClosedAgain(): void
    {
        $period = $this->openPeriod();

        self::assertSame(200, $this->closePeriod($period)->getStatusCode());

        $again = $this->closePeriod($period);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('PERIOD_ALREADY_CLOSED', $this->errorOf($again)['code'] ?? null);
    }

    public function testAPeriodThatHasNotEndedCannotBeClosed(): void
    {
        $period = $this->id(
            'INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)'
            . " VALUES ('FR', 'MONTHLY', current_date, current_date + 20) RETURNING id",
        );

        $response = $this->closePeriod($period);

        // Closing is one-way, so freezing a figure that is still moving
        // cannot be undone.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PERIOD_NOT_ENDED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAClosedPeriodReportsWhatItDeclaredNotWhatIsThereNow(): void
    {
        $this->saveTaxProfile(['country_code' => 'FR', 'customer_kind' => 'B2C']);
        $this->saveBillingProfile();
        $this->subscribe();
        $this->request('POST', '/api/v1/billing/invoices', $this->headers());

        // A period covering the invoice, already ended.
        $period = $this->id(
            'INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)'
            . " VALUES ('FR', 'MONTHLY', current_date - 5, current_date - 1) RETURNING id",
        );
        $this->connection->executeStatement(
            'UPDATE vat_transactions SET transaction_date = current_date - 3',
        );

        $closed = $this->closedDeclaration($period);
        self::assertSame(1, $closed['transaction_count'] ?? null);

        $declaredVat = $closed['total_vat'] ?? null;

        // Something arrives in the same window afterwards, which is exactly
        // the situation a closed period must not silently absorb.
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO vat_transactions
                (invoice_id, tenant_id, product_id, country, supply_type,
                 taxable_base, vat_rate, vat_amount, currency, vat_regime, rule_id, transaction_date)
            SELECT invoice_id, tenant_id, product_id, country, supply_type,
                   taxable_base, vat_rate, vat_amount, currency, vat_regime, rule_id, transaction_date
              FROM vat_transactions LIMIT 1
            SQL,
        );

        $view = $this->decode(
            $this->request('GET', '/api/v1/tax/reports/' . $period, $this->headers()),
        );

        $totals = $view['totals'] ?? null;
        self::assertIsArray($totals);

        // The declared figure, not a fresh query's answer.
        self::assertSame($declaredVat, $totals['total_vat'] ?? null);
        self::assertSame(1, $totals['transaction_count'] ?? null);
    }

    public function testTheDatabaseRefusesToReopenAClosedPeriod(): void
    {
        $period = $this->openPeriod();
        $this->closePeriod($period);

        // The service refuses with a message; the trigger refuses whatever
        // the service thinks. If the two ever disagree, this is the one that
        // holds.
        $this->expectExceptionMessageMatches('/closed and cannot be modified/');

        $this->connection->executeStatement(
            "UPDATE vat_reporting_periods SET status = 'OPEN', closed_at = NULL WHERE id = :id",
            ['id' => $period],
        );
    }

    // --- authorization ----------------------------------------------------

    public function testReadingFiscalHistoryNeedsThePermission(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], []),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/tax/transactions', $this->headers());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testClosingAPeriodNeedsMoreThanReadAccess(): void
    {
        $period = $this->openPeriod();

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->user, $this->product, ['USER'], ['tax.read']),
            ]),
        ]);

        // Closure is one-way and audited, so it takes tax.manage.
        self::assertSame(403, $this->closePeriod($period)->getStatusCode());
    }

    /**
     * `tax_rates` is seed data loaded by a migration, and TestDatabase does not
     * truncate it — rightly, since re-seeding 31 rate windows between every
     * test would be slow and pointless. The consequence is that a test which
     * *moves* a rate poisons every class running after it, which is exactly
     * what happened: FR standard stayed at 25% and three later tests invoiced
     * at a rate this class invented.
     *
     * So what this class changes, it puts back — in tearDown, which runs even
     * when an assertion fails part-way through.
     */
    protected function tearDown(): void
    {
        // The seeded rows carry a source; the ones this class inserts do not.
        $this->connection->executeStatement('DELETE FROM tax_rates WHERE source IS NULL');
        $this->connection->executeStatement(
            'UPDATE tax_rates SET valid_until = NULL'
            . " WHERE country_code = 'FR' AND rate_kind = 'STANDARD'",
        );

        parent::tearDown();
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $changes
     */
    private function saveTaxProfile(array $changes = []): ResponseInterface
    {
        $response = $this->request(
            'PUT',
            '/api/v1/tax/profile',
            $this->headers(),
            $this->json(array_merge(['customer_kind' => 'B2C'], $changes)),
        );

        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    /**
     * @param array<string, string> $changes
     */
    private function saveBillingProfile(array $changes = []): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json(array_merge([
                'legal_name' => 'Acme SARL',
                'address_line1' => '12 avenue des Champs',
                'postal_code' => '75008',
                'city' => 'Paris',
                'country_code' => 'FR',
                'billing_email' => 'compta@acme.test',
            ], $changes)),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(int $amount, ?string $on = null): array
    {
        $path = '/api/v1/tax/calculate' . ($on === null ? '' : '?on=' . $on);

        $response = $this->request(
            'POST',
            $path,
            $this->headers(),
            $this->json(['amount_minor_units' => $amount, 'currency' => 'EUR']),
        );

        self::assertSame(200, $response->getStatusCode());

        $calculation = $this->decode($response)['calculation'] ?? null;
        self::assertIsArray($calculation);

        /** @var array<string, mixed> $calculation */
        return $calculation;
    }

    private function openPeriod(): string
    {
        return $this->id(
            'INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)'
            . " VALUES ('FR', 'MONTHLY', current_date - 40, current_date - 10) RETURNING id",
        );
    }

    private function closePeriod(string $periodId): ResponseInterface
    {
        return $this->request('POST', '/api/v1/tax/reports/' . $periodId . '/close', $this->headers());
    }

    /**
     * Closes a period and returns its declaration, asserting the status on the
     * way so a refusal names itself rather than surfacing as "null is not an
     * array" three lines later.
     *
     * @return array<string, mixed>
     */
    private function closedDeclaration(string $periodId): array
    {
        $response = $this->closePeriod($periodId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $declaration = $this->decode($response)['declaration'] ?? null;
        self::assertIsArray($declaration);

        /** @var array<string, mixed> $declaration */
        return $declaration;
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

        $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 10000, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $this->offer],
        );
    }

    /**
     * The first VAT transaction, asserted down to an array so the tests read
     * as statements about fiscal facts rather than about array offsets.
     *
     * @return array<string, mixed>
     */
    private function firstFact(): array
    {
        $body = $this->decode(
            $this->request('GET', '/api/v1/tax/transactions', $this->headers()),
        );

        $transactions = $body['transactions'] ?? null;
        self::assertIsArray($transactions);
        self::assertNotSame([], $transactions);

        $fact = $transactions[0];
        self::assertIsArray($fact);

        /** @var array<string, mixed> $fact */
        return $fact;
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
