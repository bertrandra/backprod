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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * The five operational listings, and the three different permissions they
 * sit behind (§7's `/admin` block, non-negotiable #19).
 *
 * §37.4 order: the boundary first. These are the widest reads in the
 * platform — every tenant, every person, every invoice — so who is refused
 * matters more than what comes back, and holding *a* platform role is
 * deliberately not enough for any of them.
 *
 * Real database throughout, because the queries are the feature. Three of
 * the five join across tables and count with subqueries, and the one thing a
 * doubled repository could not catch is exactly what went wrong while these
 * were written: a filter parameter PostgreSQL could not type.
 */
#[CoversNothing]
final class AdminDirectoryTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenantA = '';
    private string $operator = '';
    private string $supporter = '';
    private string $accountant = '';
    private string $member = '';
    private string $erased = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenantA = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme 50% Off', 'acme') RETURNING id",
        );
        // A second customer, so the listing has more than one row to page and
        // the search has something to exclude. Nothing needs its id.
        $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");

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

        // Anonymised, not deleted (#14, #15). The identity is gone and the
        // row remains — which is exactly what /admin/users has to show.
        $this->erased = $this->id(
            'INSERT INTO users (auth_subject, email, display_name, erased_at)'
            . " VALUES ('erased:gone', NULL, NULL, now()) RETURNING id",
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
                    $this->tenantA,
                    $this->member,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary -------------------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function everyListing(): array
    {
        return [['tenants'], ['users'], ['subscriptions'], ['invoices'], ['jobs']];
    }

    #[DataProvider('everyListing')]
    public function testATenantAdminIsRefusedEveryListing(string $listing): void
    {
        $response = $this->get('/api/v1/admin/' . $listing, 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testSupportStaffCannotListEveryPersonOnThePlatform(): void
    {
        // Support is genuinely platform staff, and this is the one admin
        // surface returning personal data. Holding a platform role is not
        // holding every platform permission.
        self::assertSame(403, $this->get('/api/v1/admin/users', 'sam-token')->getStatusCode());
        self::assertSame(403, $this->get('/api/v1/admin/tenants', 'sam-token')->getStatusCode());
    }

    public function testSupportStaffMayStillSeeTheQueue(): void
    {
        // The same reason the queue endpoint gives: "why has my export not
        // arrived?" is a support question whose honest answer is sometimes
        // "the runner has not run since Tuesday".
        self::assertSame(200, $this->get('/api/v1/admin/jobs', 'sam-token')->getStatusCode());
    }

    public function testFinanceStaffSeeMoneyAndNotTheDirectory(): void
    {
        self::assertSame(200, $this->get('/api/v1/admin/invoices', 'fin-token')->getStatusCode());
        self::assertSame(200, $this->get('/api/v1/admin/subscriptions', 'fin-token')->getStatusCode());
        self::assertSame(403, $this->get('/api/v1/admin/users', 'fin-token')->getStatusCode());
    }

    // --- What comes back ----------------------------------------------------

    public function testTheTenantListingCarriesOperationalCounts(): void
    {
        $rows = $this->rowsOf($this->get('/api/v1/admin/tenants', 'ops-token'), 'tenants');

        self::assertCount(2, $rows);
        // Counted by subquery rather than by join: a tenant with three
        // subscriptions and two unpaid invoices must appear once, not six
        // times.
        self::assertArrayHasKey('members', $rows[0]);
        self::assertArrayHasKey('active_subscriptions', $rows[0]);
        self::assertArrayHasKey('unpaid_invoices', $rows[0]);
    }

    public function testASearchTreatsTheCallersOwnWildcardsAsLiteralText(): void
    {
        // "Acme 50% Off" is a real customer name. Left unescaped, `50%`
        // would match every tenant, which is the opposite of a search.
        $rows = $this->rowsOf($this->get('/api/v1/admin/tenants?search=50%25', 'ops-token'), 'tenants');

        self::assertCount(1, $rows);
        self::assertSame('Acme 50% Off', $rows[0]['name'] ?? null);
    }

    public function testAnErasedPersonAppearsWithoutAnIdentity(): void
    {
        $rows = $this->rowsOf($this->get('/api/v1/admin/users', 'ops-token'), 'users');

        $erased = null;

        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $this->erased) {
                $erased = $row;
            }
        }

        self::assertIsArray($erased, 'An erased person is kept, not deleted, so they must still be listed.');
        self::assertNull($erased['email']);
        self::assertNull($erased['display_name']);
        self::assertNotNull($erased['erased_at'] ?? null);
    }

    public function testAnErasedPersonMatchesNoSearchForAnIdentityTheyNoLongerHave(): void
    {
        $rows = $this->rowsOf($this->get('/api/v1/admin/users?search=mia', 'ops-token'), 'users');

        self::assertCount(1, $rows);
        self::assertSame('mia@acme.test', $rows[0]['email'] ?? null);
    }

    public function testTheTotalCountsBeyondThePage(): void
    {
        $body = $this->decode($this->get('/api/v1/admin/users?limit=1', 'ops-token'));

        self::assertSame(1, $body['limit'] ?? null);
        // Five people were created; the page holds one and says so.
        self::assertSame(5, $body['total'] ?? null);
        self::assertCount(1, $this->rowsOf($this->get('/api/v1/admin/users?limit=1', 'ops-token'), 'users'));
    }

    public function testAnAbsentFilterAddsNoCondition(): void
    {
        // The regression this test exists for: every filter is bound as a
        // null and cast, because a bare `:x IS NULL` leaves PostgreSQL with
        // no type to infer and it refuses the statement at prepare time —
        // on every call, not only the filtered ones.
        foreach (['tenants', 'users', 'subscriptions', 'invoices', 'jobs'] as $listing) {
            self::assertSame(
                200,
                $this->get('/api/v1/admin/' . $listing, 'ops-token')->getStatusCode(),
                $listing . ' must answer with no filters at all',
            );
        }
    }

    /**
     * Every other paginated listing in the API refuses an out-of-range limit
     * rather than quietly clamping it, because a caller who asked for 99999
     * rows and silently got 200 has no way to tell a short page from the last
     * page. The admin listings are not a special case.
     */
    public function testAnOutOfRangePageSizeIsRefusedRatherThanClamped(): void
    {
        $response = $this->get('/api/v1/admin/users?limit=99999', 'ops-token');

        self::assertSame(400, $response->getStatusCode());

        $error = $this->errorOf($response);

        self::assertSame('VALIDATION_FAILED', $error['code'] ?? null);

        $details = $error['details'] ?? null;

        self::assertIsArray($details);
        self::assertSame('limit', $details['field'] ?? null);
    }

    public function testThePageSizeDefaultsWhenItIsNotAskedFor(): void
    {
        $body = $this->decode($this->get('/api/v1/admin/users', 'ops-token'));

        self::assertSame(50, $body['limit'] ?? null);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(ResponseInterface $response, string $key): array
    {
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->decode($response)[$key] ?? null;

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
