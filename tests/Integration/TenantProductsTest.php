<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Which products a tenant holds, and the platform deciding it (ADR-047) —
 * through the real pipeline and the real database.
 *
 * **Not doubled**, for the reason `OfferAuthoringDelegationTest` gives: the
 * claim is that assigning a product makes every current member a member of
 * it, with the roles they already hold, and that withdrawing it takes those
 * memberships away. Both are SQL — an INSERT … SELECT and a cascade — and a
 * fake that returned what the fixture said would prove nothing. So the
 * fan-out is asserted the way the platform reads it: `GET /me/permissions`
 * with the new product in `X-Product`, which is the whole context chain.
 */
#[CoversNothing]
final class TenantProductsTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $boreas = '';
    private string $comet = '';
    private string $tenant = '';
    private string $ada = '';
    private string $grace = '';
    private string $admin = '';
    private string $support = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        // Retired: every door closed, and so not something to hand a customer.
        $this->comet = $this->id("INSERT INTO products (code, name, active) VALUES ('comet', 'Comet', false) RETURNING id");

        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $this->grace = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-grace', 'grace@acme.test') RETURNING id");
        $this->admin = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->support = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        // Acme holds atlas; Ada administers it and Grace is a plain member.
        TestDatabase::assignProduct($this->connection, $this->tenant, $this->atlas);
        $this->member($this->ada, $this->atlas, 'TENANT_ADMIN');
        $this->member($this->grace, $this->atlas, 'USER');

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'grace-token' => 'sub-grace',
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->atlas, 'atlas', 'Atlas', true),
                new Product($this->boreas, 'boreas', 'Boreas', true),
                new Product($this->comet, 'comet', 'Comet', false),
            ]),
        ]);
    }

    // --- Assigning ---------------------------------------------------------

    public function testAssigningAProductMakesEveryMemberAMemberOfItWithTheirRoles(): void
    {
        // Before: neither can act in boreas — Acme does not hold it.
        self::assertSame(403, $this->permissions('ada-token', 'boreas')->getStatusCode());

        $response = $this->assign($this->boreas, 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['atlas', 'boreas'], $this->productCodesIn($response));

        // After: the chain resolves them in boreas, each with what they hold
        // in the organisation — Ada as administrator, Grace as member.
        $ada = $this->decode($this->permissions('ada-token', 'boreas'));
        $grace = $this->decode($this->permissions('grace-token', 'boreas'));

        self::assertContains('TENANT_ADMIN', $this->stringsIn($ada, 'roles'));
        self::assertContains('members.manage', $this->stringsIn($ada, 'permissions'));
        self::assertSame(['USER'], $this->stringsIn($grace, 'roles'));
        self::assertNotContains('members.manage', $this->stringsIn($grace, 'permissions'));
    }

    public function testAssigningTwiceIsTheStateThatWasAskedFor(): void
    {
        self::assertSame(200, $this->assign($this->boreas, 'ola-token')->getStatusCode());

        $again = $this->assign($this->boreas, 'ola-token');

        self::assertSame(200, $again->getStatusCode());
        self::assertSame(['atlas', 'boreas'], $this->productCodesIn($again));
        self::assertSame(2, $this->membershipsOf($this->ada));
    }

    public function testAMemberAddedLaterIsAMemberOfEveryProductTheTenantHolds(): void
    {
        self::assertSame(200, $this->assign($this->boreas, 'ola-token')->getStatusCode());

        // Ada, acting in atlas, invites a colleague. The invitation is to the
        // organisation, so the colleague can act in boreas as well.
        $invited = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-new', 'new@acme.test') RETURNING id");
        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'new-token' => 'sub-new',
            ]),
        ]);

        $added = $this->request(
            'POST',
            '/api/v1/tenants/current/members',
            ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'],
            $this->json(['email' => 'new@acme.test', 'roles' => ['USER']]),
        );

        self::assertSame(201, $added->getStatusCode());
        self::assertSame(2, $this->membershipsOf($invited));
        self::assertSame(200, $this->permissions('new-token', 'boreas')->getStatusCode());
    }

    public function testARetiredProductCannotBeAssigned(): void
    {
        $response = $this->assign($this->comet, 'ola-token');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PRODUCT_INACTIVE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(1, $this->membershipsOf($this->ada));
    }

    // --- Withdrawing -------------------------------------------------------

    public function testWithdrawingAProductRemovesTheMembershipsInIt(): void
    {
        self::assertSame(200, $this->assign($this->boreas, 'ola-token')->getStatusCode());
        self::assertSame(2, $this->membershipsOf($this->ada));

        $response = $this->unassign($this->boreas, 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['atlas'], $this->productCodesIn($response));
        self::assertSame(1, $this->membershipsOf($this->ada));
        self::assertSame(1, $this->roleRowsOf($this->ada));
        self::assertSame(403, $this->permissions('ada-token', 'boreas')->getStatusCode());
        // And nothing happened to atlas.
        self::assertSame(200, $this->permissions('ada-token', 'atlas')->getStatusCode());
    }

    public function testWithdrawingAProductStillOwedServiceIsRefused(): void
    {
        $this->subscribe($this->atlas, 'ACTIVE', '+20 days');

        $response = $this->unassign($this->atlas, 'ola-token');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PRODUCT_IN_USE', $this->errorOf($response)['code'] ?? null);
        self::assertSame(1, $this->membershipsOf($this->ada));
    }

    public function testACancelledSubscriptionWithPaidTimeLeftStillHoldsTheProduct(): void
    {
        // Cancelled, but the period is paid for until next month: the
        // service is owed (§13.1), so the product stays.
        $this->subscribe($this->atlas, 'CANCELLED', '+20 days');

        self::assertSame(409, $this->unassign($this->atlas, 'ola-token')->getStatusCode());
    }

    public function testAnExpiredSubscriptionNoLongerHoldsTheProduct(): void
    {
        $this->subscribe($this->atlas, 'EXPIRED', '-20 days');

        self::assertSame(200, $this->unassign($this->atlas, 'ola-token')->getStatusCode());
        self::assertSame(0, $this->membershipsOf($this->ada));
    }

    public function testWithdrawingAProductNeverHeldIsTheStateThatWasAskedFor(): void
    {
        $response = $this->unassign($this->boreas, 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['atlas'], $this->productCodesIn($response));
    }

    // --- What the reads say --------------------------------------------------

    public function testTheListAndTheReadCarryTheProducts(): void
    {
        self::assertSame(200, $this->assign($this->boreas, 'ola-token')->getStatusCode());

        $list = $this->decode($this->request('GET', '/api/v1/staff/tenants', ['Authorization' => 'Bearer sam-token']));
        $tenants = $list['tenants'] ?? [];
        self::assertIsArray($tenants);
        self::assertCount(1, $tenants);
        self::assertSame(['atlas', 'boreas'], $this->codesOf($tenants[0]));

        $read = $this->decode($this->request('GET', '/api/v1/staff/tenants/' . $this->tenant, [
            'Authorization' => 'Bearer sam-token',
            'X-Access-Purpose' => 'SUPPORT_REQUEST',
            'X-Access-Reason' => 'ticket-4711',
        ]));
        self::assertSame(['atlas', 'boreas'], $this->codesOf($read['tenant'] ?? []));
    }

    // --- Who may decide ------------------------------------------------------

    public function testSupportMayLookButNotDecide(): void
    {
        self::assertSame(403, $this->assign($this->boreas, 'sam-token')->getStatusCode());
        self::assertSame(403, $this->unassign($this->atlas, 'sam-token')->getStatusCode());
        self::assertSame(1, $this->membershipsOf($this->ada));
    }

    public function testATenantAdministratorIsNotStaff(): void
    {
        self::assertSame(403, $this->assign($this->boreas, 'ada-token')->getStatusCode());
    }

    public function testUnknownTenantOrProductIsNotFound(): void
    {
        $missing = '00000000-0000-4000-8000-000000000000';

        self::assertSame(404, $this->assign($this->boreas, 'ola-token', $missing)->getStatusCode());
        self::assertSame(404, $this->assign($missing, 'ola-token')->getStatusCode());
        self::assertSame(404, $this->unassign($missing, 'ola-token')->getStatusCode());
    }

    // --- The trail -----------------------------------------------------------

    public function testEveryDecisionIsOnTheTrailWithTheProductItConcerned(): void
    {
        $this->assign($this->boreas, 'ola-token');
        $this->unassign($this->boreas, 'ola-token');

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT action, tenant_id, product_id, permission
                  FROM staff_access_log
                 WHERE action IN ('ASSIGN_PRODUCT', 'UNASSIGN_PRODUCT')
                 ORDER BY occurred_at, action
                SQL,
        );

        self::assertSame(
            [
                ['ASSIGN_PRODUCT', $this->tenant, $this->boreas, 'staff.tenants.manage'],
                ['UNASSIGN_PRODUCT', $this->tenant, $this->boreas, 'staff.tenants.manage'],
            ],
            array_map(static fn (array $row): array => array_values($row), $rows),
        );
    }

    // --- Helpers -------------------------------------------------------------

    private function assign(string $productId, string $token, ?string $tenantId = null): ResponseInterface
    {
        return $this->request(
            'PUT',
            '/api/v1/staff/tenants/' . ($tenantId ?? $this->tenant) . '/products/' . $productId,
            ['Authorization' => 'Bearer ' . $token],
        );
    }

    private function unassign(string $productId, string $token, ?string $tenantId = null): ResponseInterface
    {
        return $this->request(
            'DELETE',
            '/api/v1/staff/tenants/' . ($tenantId ?? $this->tenant) . '/products/' . $productId,
            ['Authorization' => 'Bearer ' . $token],
        );
    }

    private function permissions(string $token, string $productCode): ResponseInterface
    {
        return $this->request('GET', '/api/v1/me/permissions', [
            'Authorization' => 'Bearer ' . $token,
            'X-Product' => $productCode,
        ]);
    }

    /**
     * @return list<string>
     */
    private function productCodesIn(ResponseInterface $response): array
    {
        return $this->codesOf($this->decode($response)['tenant'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function codesOf(mixed $tenant): array
    {
        self::assertIsArray($tenant);
        $products = $tenant['products'] ?? null;
        self::assertIsArray($products);

        $codes = [];

        foreach ($products as $product) {
            self::assertIsArray($product);
            self::assertIsString($product['code'] ?? null);
            $codes[] = $product['code'];
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private function stringsIn(array $source, string $key): array
    {
        $values = $source[$key] ?? null;
        self::assertIsArray($values);

        $strings = [];

        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }

    private function membershipsOf(string $userId): int
    {
        return $this->rowCount('SELECT count(*) FROM tenant_members WHERE user_id = :user', ['user' => $userId]);
    }

    private function roleRowsOf(string $userId): int
    {
        return $this->rowCount('SELECT count(*) FROM tenant_member_roles WHERE user_id = :user', ['user' => $userId]);
    }

    private function member(string $userId, string $productId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $this->tenant, 'user' => $userId, 'product' => $productId],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                SELECT :tenant, :user, :product, id FROM roles WHERE code = :role
                SQL,
            ['tenant' => $this->tenant, 'user' => $userId, 'product' => $productId, 'role' => $role],
        );
    }

    private function subscribe(string $productId, string $status, string $periodEnd): void
    {
        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $productId],
        );
        $offer = $this->id(
            "INSERT INTO offers (product_id, plan_id, code, name) VALUES (:product, :plan, 'pro', 'Pro') RETURNING id",
            ['product' => $productId, 'plan' => $plan],
        );
        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 10000, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $offer],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, status, subscriber_kind,
                     started_at, current_period_start, current_period_end, ended_at)
                VALUES (:tenant, :product, :version, :status, 'TENANT',
                        now() - interval '40 days', now() - interval '40 days',
                        CAST(:periodEnd AS timestamptz),
                        CASE WHEN :status = 'ACTIVE' THEN NULL ELSE now() - interval '1 day' END)
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $productId,
                'version' => $version,
                'status' => $status,
                'periodEnd' => (new \DateTimeImmutable($periodEnd))->format(\DateTimeInterface::ATOM),
            ],
        );
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function rowCount(string $sql, array $parameters): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }
}
