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
 * `/admin/payments` — whether the money arrived (§7's `/admin` block,
 * non-negotiable #19).
 *
 * The listing beside `/admin/invoices`, and the one that was never built: the
 * platform could read every document ever raised and not one payment against
 * one of them, so *"did this customer actually pay?"* had no answer here at
 * all.
 *
 * §37.4 order: the boundary first. This is a cross-tenant read of money, so
 * who is refused matters more than what comes back, and holding *a* platform
 * role is deliberately not enough.
 *
 * Real database, because the query is the feature. It joins three tables and
 * reads the customer out of a JSONB snapshot; a doubled repository would prove
 * nothing about either.
 */
#[CoversNothing]
final class AdminPaymentsTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $acme = '';
    private string $globex = '';
    private string $operator = '';
    private string $supporter = '';
    private string $accountant = '';
    private string $member = '';
    private string $settled = '';
    private string $refused = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );

        $this->acme = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id",
        );
        // A second customer, because the whole point of this listing is that
        // the platform sees across them.
        $this->globex = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id",
        );

        $this->operator = $this->id(
            'INSERT INTO users (auth_subject, email, display_name)'
            . " VALUES ('sub-ops', 'ops@platform.test', 'Ops') RETURNING id",
        );
        $this->supporter = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->accountant = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-fin', 'fin@platform.test') RETURNING id",
        );
        $this->member = $this->id(
            'INSERT INTO users (auth_subject, email, display_name)'
            . " VALUES ('sub-mia', 'mia@acme.test', 'Mia') RETURNING id",
        );

        // Acme's own invoice for a seat (ADR-055): the organisation sold it,
        // one of its people bought it, and the document says so. Its customer
        // is a *person*, which is exactly the fact a payments list has to be
        // able to show.
        $issued = $this->id(
            <<<'SQL'
                INSERT INTO invoices (
                    tenant_id, product_id, number, status, currency,
                    net_minor_units, vat_minor_units, gross_minor_units, issued_at,
                    supplier_snapshot, customer_snapshot
                ) VALUES (
                    :tenant, :product, '2026-000004', 'PAID', 'EUR',
                    2900, 580, 3480, now(),
                    '{"legal_name": "Acme Ltd"}',
                    '{"legal_name": "Ada Lovelace", "person": {"email": "ada@acme.test"}}'
                ) RETURNING id
                SQL,
            ['tenant' => $this->acme, 'product' => $this->product],
        );

        // Globex is still a draft, so it has no number at all — and none may
        // be invented for it.
        $draft = $this->id(
            <<<'SQL'
                INSERT INTO invoices (
                    tenant_id, product_id, status, currency,
                    net_minor_units, vat_minor_units, gross_minor_units,
                    supplier_snapshot, customer_snapshot
                ) VALUES (
                    :tenant, :product, 'DRAFT', 'EUR',
                    1000, 200, 1200,
                    '{"legal_name": "Atlas SAS"}',
                    '{"legal_name": "Globex Worldwide SA", "billing_email": "ap@globex.test"}'
                ) RETURNING id
                SQL,
            ['tenant' => $this->globex, 'product' => $this->product],
        );

        $this->settled = $this->id(
            <<<'SQL'
                INSERT INTO payments (
                    tenant_id, product_id, invoice_id, provider, provider_payment_id,
                    status, amount_minor_units, currency, method, succeeded_at, created_at
                ) VALUES (
                    :tenant, :product, :invoice, 'stripe', 'pi_settled',
                    'SUCCEEDED', 3480, 'EUR', 'CARD', now(), now()
                ) RETURNING id
                SQL,
            ['tenant' => $this->acme, 'product' => $this->product, 'invoice' => $issued],
        );

        $this->refused = $this->id(
            <<<'SQL'
                INSERT INTO payments (
                    tenant_id, product_id, invoice_id, provider, provider_payment_id,
                    status, amount_minor_units, currency, method,
                    failure_code, failure_reason, failed_at, created_at
                ) VALUES (
                    :tenant, :product, :invoice, 'stripe', 'pi_refused',
                    'FAILED', 1200, 'EUR', 'CARD',
                    'card_declined', 'The card was declined.', now(), now() - interval '1 hour'
                ) RETURNING id
                SQL,
            ['tenant' => $this->globex, 'product' => $this->product, 'invoice' => $draft],
        );

        $this->grantRole($this->operator, 'PLATFORM_ADMIN');
        $this->grantRole($this->supporter, 'SUPPORT_ADMIN');
        $this->grantRole($this->accountant, 'FINANCE_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ops-token' => 'sub-ops',
                'sam-token' => 'sub-sam',
                'fin-token' => 'sub-fin',
                'mia-token' => 'sub-mia',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->acme,
                    $this->member,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage', 'billing.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary -------------------------------------------------------

    public function testACustomersOwnAdministratorIsRefused(): void
    {
        // `billing.manage` lets Mia read *Acme's* payments on the tenant
        // surface. It is not a key to everybody else's, and the two surfaces
        // are separate end to end (§12.2).
        $response = $this->get('/api/v1/admin/payments', 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testSupportStaffAreRefusedAndFinanceStaffAreNot(): void
    {
        // The permission the controller requires is `admin.finance.read`, held
        // by FINANCE_ADMIN and deliberately not by SUPPORT_ADMIN: support
        // answers one customer's question about their own account, which is
        // not a reason to see every customer's money.
        self::assertSame(403, $this->get('/api/v1/admin/payments', 'sam-token')->getStatusCode());
        self::assertSame(200, $this->get('/api/v1/admin/payments', 'fin-token')->getStatusCode());
    }

    // --- What comes back ----------------------------------------------------

    public function testEveryTenantsPaymentsAreVisibleToThePlatform(): void
    {
        // The point of the listing. A tenant-scoped read would show one of
        // these and the operator would have to ask each customer in turn.
        $rows = $this->rowsOf($this->get('/api/v1/admin/payments', 'fin-token'));

        $byId = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            self::assertIsString($id);

            $byId[$id] = $row;
        }

        self::assertArrayHasKey($this->settled, $byId, "Acme's payment must be listed.");
        self::assertArrayHasKey($this->refused, $byId, "Globex's payment must be listed too.");

        self::assertSame('Acme Ltd', $byId[$this->settled]['tenant_name'] ?? null);
        self::assertSame('Globex', $byId[$this->refused]['tenant_name'] ?? null);
        // Which product was being paid for: the platform is multi-product, and
        // a payments list that did not say would be four lists in one.
        self::assertSame('atlas', $byId[$this->settled]['product_code'] ?? null);
    }

    public function testEachRowNamesTheDocumentItCollectsAndItsAmount(): void
    {
        $row = $this->rowFor($this->settled);

        self::assertSame('2026-000004', $row['invoice_number'] ?? null);
        // Integer minor units with the currency beside them, never a float and
        // never a total assembled here.
        self::assertSame(3480, $row['amount_minor_units'] ?? null);
        self::assertSame('EUR', $row['currency'] ?? null);
        self::assertSame('SUCCEEDED', $row['status'] ?? null);
        self::assertSame('stripe', $row['provider'] ?? null);
        self::assertNotNull($row['succeeded_at'] ?? null);
    }

    public function testTheCustomerComesFromTheDocumentsSnapshotAndNotFromALiveRow(): void
    {
        // §25: who the parties were when the document was raised. Acme sold a
        // seat to Ada, so the invoice's customer is *Ada* — reading the tenant
        // instead would put the seller's name in the buyer's place, and the
        // three seats Acme sells would read as three identical rows.
        $before = $this->rowFor($this->settled);

        self::assertSame('Ada Lovelace', $before['customer_name'] ?? null);
        self::assertSame('ada@acme.test', $before['customer_email'] ?? null);
        self::assertNotSame('Acme Ltd', $before['customer_name'] ?? null);

        // And it stays that name when the organisation renames itself. A
        // payment reported under a name the customer's paper copy does not
        // carry is a payment nobody can reconcile.
        $this->connection->executeStatement(
            'UPDATE tenants SET name = :name WHERE id = :id',
            ['name' => 'Acme International Ltd', 'id' => $this->acme],
        );

        $after = $this->rowFor($this->settled);

        self::assertSame('Ada Lovelace', $after['customer_name'] ?? null);
        // The tenant's *own* column follows the rename, which is what makes
        // the snapshot's stability a real distinction rather than a tautology.
        self::assertSame('Acme International Ltd', $after['tenant_name'] ?? null);
    }

    public function testAnAddressFallsBackToTheBillingBlockWhenTheDocumentNamesNoPerson(): void
    {
        // Two shapes of one snapshot: a seat's invoice is raised to a person,
        // an organisation's to an organisation.
        $row = $this->rowFor($this->refused);

        self::assertSame('Globex Worldwide SA', $row['customer_name'] ?? null);
        self::assertSame('ap@globex.test', $row['customer_email'] ?? null);
    }

    public function testADraftInvoiceLendsNoNumberAndNoPlaceholderIsInvented(): void
    {
        // A number comes from a gapless sequence at issue. A stand-in here is
        // how a hole enters one, and a hole is a question from an auditor.
        $row = $this->rowFor($this->refused);

        self::assertArrayHasKey('invoice_number', $row);
        self::assertNull($row['invoice_number']);
        self::assertSame('card_declined', $row['failure_code'] ?? null);
        self::assertNull($row['succeeded_at']);
    }

    // --- Filters and bounds -------------------------------------------------

    public function testAnAbsentFilterAddsNoCondition(): void
    {
        // The regression the other five listings were written into: every
        // filter is bound as a null and cast, because a bare `:x IS NULL`
        // leaves PostgreSQL no type to infer and it refuses the statement at
        // prepare time — on every call, not only the filtered ones.
        self::assertCount(2, $this->rowsOf($this->get('/api/v1/admin/payments', 'fin-token')));
    }

    public function testEachFilterNarrows(): void
    {
        $failed = $this->rowsOf($this->get('/api/v1/admin/payments?status=FAILED', 'fin-token'));

        self::assertCount(1, $failed);
        self::assertSame($this->refused, $failed[0]['id'] ?? null);

        $acme = $this->rowsOf(
            $this->get('/api/v1/admin/payments?tenant_id=' . $this->acme, 'fin-token'),
        );

        self::assertCount(1, $acme);
        self::assertSame($this->settled, $acme[0]['id'] ?? null);

        self::assertCount(
            2,
            $this->rowsOf($this->get('/api/v1/admin/payments?product_id=' . $this->product, 'fin-token')),
        );
    }

    public function testThePageIsBoundedAndTheTotalCountsBeyondIt(): void
    {
        // A payments list grows faster than any other admin list: one invoice
        // can carry several attempts, and a failed card is retried.
        $body = $this->decode($this->get('/api/v1/admin/payments?limit=1', 'fin-token'));

        self::assertSame(1, $body['limit'] ?? null);
        self::assertSame(2, $body['total'] ?? null);
        self::assertCount(1, $this->rowsOf($this->get('/api/v1/admin/payments?limit=1', 'fin-token')));

        // Newest first, so the page the operator lands on is the one they came
        // for.
        $first = $this->rowsOf($this->get('/api/v1/admin/payments?limit=1', 'fin-token'))[0];

        self::assertSame($this->settled, $first['id'] ?? null);
    }

    public function testAnOutOfRangePageSizeIsRefusedRatherThanClamped(): void
    {
        // A caller who asked for 99999 rows and silently got 200 cannot tell a
        // short page from the last page.
        $response = $this->get('/api/v1/admin/payments?limit=99999', 'fin-token');

        self::assertSame(400, $response->getStatusCode());

        $error = $this->errorOf($response);

        self::assertSame('VALIDATION_FAILED', $error['code'] ?? null);

        $details = $error['details'] ?? null;

        self::assertIsArray($details);
        self::assertSame('limit', $details['field'] ?? null);
    }

    public function testThePageSizeDefaultsWhenItIsNotAskedFor(): void
    {
        $body = $this->decode($this->get('/api/v1/admin/payments', 'fin-token'));

        self::assertSame(50, $body['limit'] ?? null);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $paymentId): array
    {
        foreach ($this->rowsOf($this->get('/api/v1/admin/payments', 'fin-token')) as $row) {
            if (($row['id'] ?? null) === $paymentId) {
                return $row;
            }
        }

        self::fail('The listing does not carry payment ' . $paymentId . '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->decode($response)['payments'] ?? null;

        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function grantRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
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
