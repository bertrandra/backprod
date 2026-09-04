<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Finance\Infrastructure\PostgresFinancialPeriods;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §25.2's first three figures, and who may see them.
 *
 * Isolation first (§37.4). M8's exit criterion is that a TENANT_ADMIN
 * provably cannot read cross-tenant financials, and this is the endpoint that
 * would leak them — every tenant's revenue, in one response, behind one
 * permission.
 *
 * Support staff are refused too. They hold a platform role, so the boundary
 * that stops them is the permission rather than the surface, and a test that
 * only proved a customer is refused would not have tested it.
 */
#[CoversNothing]
final class AdminMetricsTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $offer = '';
    private string $offerVersion = '';
    private string $financeUser = '';
    private string $supporter = '';
    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->financeUser = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-fin', 'fin@platform.test') RETURNING id",
        );
        $this->supporter = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );

        $this->grantRole($this->financeUser, 'FINANCE_ADMIN');
        $this->grantRole($this->supporter, 'SUPPORT_ADMIN');
        $this->seedCatalogue();

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'fin-token' => 'sub-fin',
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->member,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary --------------------------------------------------------

    public function testATenantAdminCannotReadPlatformFinancials(): void
    {
        $response = $this->get('/api/v1/admin/metrics?product_id=' . $this->product, 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    /**
     * Platform staff, but support: answering one customer's question about
     * their own account is not a reason to see every customer's revenue.
     */
    public function testSupportStaffCannotReadFinancials(): void
    {
        $response = $this->get('/api/v1/admin/metrics?product_id=' . $this->product, 'sam-token');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        $response = $this->request('GET', '/api/v1/admin/metrics');

        self::assertSame(401, $response->getStatusCode());
    }

    // --- Turnover ------------------------------------------------------------

    /**
     * Invoiced, not collected — and a draft and a cancellation are neither.
     */
    public function testTurnoverCountsIssuedInvoicesOnly(): void
    {
        $this->invoice('F-1', 'PAID', 10000, 2000, 12000, paid: true);
        $this->invoice('F-2', 'ISSUED', 5000, 1000, 6000);
        $this->invoice('F-3', 'DRAFT', 9999, 0, 9999, issued: false);
        $this->invoice('F-4', 'CANCELLED', 7777, 0, 7777);

        $this->rollUp();
        $month = $this->firstTurnover();

        self::assertSame(15000, $month['net_minor_units'] ?? null);
        self::assertSame(3000, $month['vat_minor_units'] ?? null);
        self::assertSame(2, $month['invoices_issued'] ?? null);
        self::assertSame(1, $month['invoices_paid'] ?? null);
        self::assertFalse($month['closed'] ?? null);
    }

    /**
     * Credits are reported beside turnover, never subtracted from it: "what
     * did we bill" and "what do we keep" are different questions.
     */
    public function testCreditNotesSitBesideTurnoverRatherThanInsideIt(): void
    {
        $invoice = $this->invoice('F-1', 'ISSUED', 10000, 2000, 12000);
        $this->creditNote($invoice, 1000, 200, 1200);

        $this->rollUp();
        $month = $this->firstTurnover();

        self::assertSame(10000, $month['net_minor_units'] ?? null);
        self::assertSame(1000, $month['credited_minor_units'] ?? null);
    }

    /**
     * The open month is meant to be recomputed. A rollup that accumulated
     * instead of replacing would double the year by December.
     */
    public function testRunningTheRollUpTwiceDoesNotDoubleTheMonth(): void
    {
        $this->invoice('F-1', 'ISSUED', 10000, 2000, 12000);

        $this->rollUp();
        $this->rollUp();

        self::assertSame(10000, $this->firstTurnover()['net_minor_units'] ?? null);
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM revenue_periods'));
    }

    // --- Top offers ----------------------------------------------------------

    public function testOffersAreRankedByWhatTheirLinesEarned(): void
    {
        $invoice = $this->invoice('F-1', 'ISSUED', 10000, 2000, 12000);
        $this->line($invoice, 10000, 2000, 12000);

        $this->rollUp();

        $body = $this->decode($this->get(
            '/api/v1/admin/metrics?product_id=' . $this->product,
            'fin-token',
        ));

        $top = $body['top_offers'] ?? null;
        self::assertIsArray($top);

        $offers = $top['offers'] ?? null;
        self::assertIsArray($offers);
        self::assertCount(1, $offers);

        $first = $offers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('pro', $first['code'] ?? null);
        self::assertSame(10000, $first['net_minor_units'] ?? null);
    }

    // --- Renewal -------------------------------------------------------------

    /**
     * The metric that must not read zero.
     *
     * Nothing renews yet — a finished period goes to EXPIRED and no job calls
     * renew() — so with no boundary reached at all the honest answer is "not
     * measured", not "0% renewed".
     */
    public function testRenewalIsUnmeasuredRatherThanZeroWhenNothingCameUp(): void
    {
        $this->rollUp();

        $body = $this->decode($this->get(
            '/api/v1/admin/metrics?product_id=' . $this->product,
            'fin-token',
        ));

        // No boundary reached, so no row at all — and certainly no 0%.
        self::assertSame([], $body['renewal'] ?? null);
    }

    public function testRenewalIsCountedFromWhatHappenedAtTheBoundary(): void
    {
        $subscription = $this->subscription();
        $this->subscriptionEvent($subscription, 'RENEWED');
        $this->subscriptionEvent($subscription, 'RENEWED');
        $this->subscriptionEvent($subscription, 'EXPIRED');

        $this->rollUp();

        $body = $this->decode($this->get(
            '/api/v1/admin/metrics?product_id=' . $this->product,
            'fin-token',
        ));

        $renewal = $body['renewal'] ?? null;
        self::assertIsArray($renewal);

        $month = $renewal[0] ?? null;
        self::assertIsArray($month);
        self::assertSame(3, $month['due'] ?? null);
        self::assertSame(2, $month['renewed'] ?? null);
        self::assertSame(1, $month['ended'] ?? null);
        self::assertSame(66.7, $month['rate_percent'] ?? null);
        self::assertTrue($month['measured'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function firstTurnover(): array
    {
        $body = $this->decode($this->get(
            '/api/v1/admin/metrics?product_id=' . $this->product,
            'fin-token',
        ));

        $turnover = $body['turnover'] ?? null;
        self::assertIsArray($turnover);

        $first = $turnover[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    private function rollUp(): void
    {
        (new PostgresFinancialPeriods($this->connection))->rollUp(1);
    }

    private function invoice(
        string $number,
        string $status,
        int $net,
        int $vat,
        int $gross,
        bool $paid = false,
        bool $issued = true,
    ): string {
        // The moments are passed as values, not as flags a CASE tests.
        // DBAL binds a PHP false as '' and PostgreSQL will not read that as a
        // boolean, so `CASE WHEN :issued` fails on exactly the rows the test
        // is about. Sending the timestamp itself sidesteps the question.
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:sP');

        return $this->id(
            <<<'SQL'
                INSERT INTO invoices (tenant_id, product_id, number, status, currency,
                                      issued_at, paid_at, net_minor_units, vat_minor_units,
                                      gross_minor_units, supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, :number, :status, 'EUR',
                        CAST(:issuedAt AS TIMESTAMPTZ), CAST(:paidAt AS TIMESTAMPTZ),
                        :net, :vat, :gross, '{}', '{}')
                RETURNING id
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'number' => $number,
                'status' => $status,
                'issuedAt' => $issued ? $now : null,
                'paidAt' => $paid ? $now : null,
                'net' => $net,
                'vat' => $vat,
                'gross' => $gross,
            ],
        );
    }

    private function line(string $invoiceId, int $net, int $vat, int $gross): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoice_lines (invoice_id, position, description, quantity,
                                           unit_price_minor_units, discount_minor_units,
                                           net_minor_units, vat_rate_basis_points,
                                           vat_minor_units, gross_minor_units,
                                           source_offer_version_id)
                VALUES (:invoice, 1, 'Pro', 1, :net, 0, :net, 2000, :vat, :gross, :version)
                SQL,
            [
                'invoice' => $invoiceId,
                'net' => $net,
                'vat' => $vat,
                'gross' => $gross,
                'version' => $this->offerVersion,
            ],
        );
    }

    private function creditNote(string $invoiceId, int $net, int $vat, int $gross): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO credit_notes (tenant_id, product_id, invoice_id, number, currency,
                                          issued_at, net_minor_units, vat_minor_units,
                                          gross_minor_units, supplier_snapshot,
                                          customer_snapshot, reason)
                VALUES (:tenant, :product, :invoice, 'A-1', 'EUR', now(),
                        :net, :vat, :gross, '{}', '{}', 'goodwill')
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'invoice' => $invoiceId,
                'net' => $net,
                'vat' => $vat,
                'gross' => $gross,
            ],
        );
    }

    private function subscription(): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO subscriptions (tenant_id, product_id, offer_version_id, status, started_at)
                VALUES (:tenant, :product, :version, 'ACTIVE', now())
                RETURNING id
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'version' => $this->offerVersion,
            ],
        );
    }

    private function subscriptionEvent(string $subscriptionId, string $type): void
    {
        $this->connection->executeStatement(
            'INSERT INTO subscription_events (subscription_id, type, occurred_at)'
            . ' VALUES (:subscription, :type, now())',
            ['subscription' => $subscriptionId, 'type' => $type],
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
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 10000, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $this->offer],
        );
    }

    private function grantRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id)'
            . ' SELECT :user, id FROM platform_roles WHERE code = :role',
            ['user' => $userId, 'role' => $role],
        );
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
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
