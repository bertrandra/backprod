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

/**
 * An organisation at its own URL root (2026-09-17): made by the platform,
 * addressed by its slug, and the bare host addressed to one of them.
 *
 * Held here: the slug selects among a person's memberships exactly as the
 * id does and never beyond them; a stranger at a root learns the name and
 * nothing else; the window at a root shows that organisation's products
 * only; staff make and re-address organisations, and an address with an
 * invoice behind it does not move.
 */
#[CoversNothing]
final class TenantRootsTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $boreas = '';
    private string $acme = '';
    private string $globex = '';
    private string $ada = '';
    private string $ola = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        $this->ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $this->ola = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");

        // Acme holds both products, Globex atlas alone; Ada administers both.
        foreach ([[$this->acme, $this->atlas], [$this->acme, $this->boreas], [$this->globex, $this->atlas]] as [$tenant, $product]) {
            TestDatabase::assignProduct($this->connection, $tenant, $product);
            $this->member($tenant, $this->ada, $product, 'TENANT_ADMIN');
        }

        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'",
            ['user' => $this->ola],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada', 'ola-token' => 'sub-ola']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->atlas, 'atlas', 'Atlas', true),
                new Product($this->boreas, 'boreas', 'Boreas', true),
            ]),
        ]);
    }

    // --- The slug as selection -------------------------------------------------

    public function testTheSlugSelectsAmongMembershipsLikeTheId(): void
    {
        $byId = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas', 'X-Tenant' => $this->globex]);
        $bySlug = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas', 'X-Tenant' => 'globex']);

        self::assertSame(200, $bySlug->getStatusCode());
        self::assertSame($this->decode($byId)['tenant_id'] ?? null, $this->decode($bySlug)['tenant_id'] ?? null);
        self::assertSame($this->globex, $this->decode($bySlug)['tenant_id'] ?? null);
    }

    public function testASlugOfAnOrganisationOneIsNotInIsRefusedLikeAnUnknownOne(): void
    {
        $this->id("INSERT INTO tenants (name, slug) VALUES ('Initech', 'initech') RETURNING id");

        $foreign = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas', 'X-Tenant' => 'initech']);
        $absent = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas', 'X-Tenant' => 'nowhere']);

        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame($this->errorOf($foreign)['code'] ?? null, $this->errorOf($absent)['code'] ?? null);
    }

    // --- The public root -------------------------------------------------------

    public function testAStrangerAtARootLearnsTheNameAndNothingElse(): void
    {
        $response = $this->request('GET', '/api/v1/public/tenant?tenant=acme');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tenant' => ['slug' => 'acme', 'name' => 'Acme Ltd', 'is_default' => false, 'join_policy' => 'OPEN', 'after_sign_up' => 'PAY']], $this->decode($response));

        self::assertSame(404, $this->request('GET', '/api/v1/public/tenant?tenant=nowhere')->getStatusCode());
        // No default set: the bare host is nobody's, and says so.
        $bare = $this->request('GET', '/api/v1/public/tenant');
        self::assertSame(404, $bare->getStatusCode());
        self::assertSame('NO_DEFAULT_TENANT', $this->errorOf($bare)['code'] ?? null);
    }

    public function testTheWindowAtARootShowsThatOrganisationsProductsOnly(): void
    {
        $this->advertise($this->atlas, 'atlas-pro');
        $this->advertise($this->boreas, 'boreas-pro');

        $everywhere = $this->decode($this->request('GET', '/api/v1/public/products'));
        $atGlobex = $this->decode($this->request('GET', '/api/v1/public/products?tenant=globex'));
        $atNowhere = $this->decode($this->request('GET', '/api/v1/public/products?tenant=nowhere'));

        self::assertSame(['atlas', 'boreas'], $this->codesIn($everywhere));
        self::assertSame(['atlas'], $this->codesIn($atGlobex));
        self::assertSame([], $atNowhere['products'] ?? null);

        // And a window for a product the organisation does not hold is empty.
        $window = $this->decode($this->request('GET', '/api/v1/public/offers?product=boreas&tenant=globex'));
        self::assertArrayHasKey('product', $window);
        self::assertNull($window['product']);
    }

    // --- Staff make and re-address organisations ------------------------------------

    public function testStaffMakeAnOrganisationWithItsProductsAndFirstAdministrator(): void
    {
        $response = $this->request('POST', '/api/v1/staff/tenants', $this->staff(), $this->json([
            'name' => 'Initech',
            'slug' => 'initech',
            'products' => ['atlas'],
            'admin_user_id' => $this->ada,
        ]));

        self::assertSame(201, $response->getStatusCode());
        $tenant = $this->decode($response)['tenant'] ?? null;
        self::assertIsArray($tenant);
        self::assertSame('initech', $tenant['slug'] ?? null);
        self::assertSame(['atlas'], $this->codesIn($tenant));
        self::assertFalse($tenant['is_default'] ?? null);

        // Ada administers it, through the real chain.
        $me = $this->decode($this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas', 'X-Tenant' => 'initech']));
        $roles = $me['roles'] ?? null;
        self::assertIsArray($roles);
        self::assertContains('TENANT_ADMIN', $roles);

        // Recorded.
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM staff_access_log WHERE action = 'CREATE' AND resource_type = 'tenant'"));
    }

    public function testASlugIsAnAddressAndSoIsCheckedAsOne(): void
    {
        $taken = $this->request('POST', '/api/v1/staff/tenants', $this->staff(), $this->json(['name' => 'Another Acme', 'slug' => 'acme']));
        self::assertSame(409, $taken->getStatusCode());
        self::assertSame('SLUG_TAKEN', $this->errorOf($taken)['code'] ?? null);

        $reserved = $this->request('POST', '/api/v1/staff/tenants', $this->staff(), $this->json(['name' => 'Console Co', 'slug' => 'console']));
        self::assertSame(400, $reserved->getStatusCode());

        $malformed = $this->request('POST', '/api/v1/staff/tenants', $this->staff(), $this->json(['name' => 'Acme Two', 'slug' => 'Acme Two']));
        self::assertSame(400, $malformed->getStatusCode());
    }

    public function testTheBareHostIsMovedToAnOrganisation(): void
    {
        $response = $this->request('PATCH', '/api/v1/staff/tenants/' . $this->acme, $this->staff(), $this->json(['is_default' => true, 'name' => 'Acme Limited']));

        self::assertSame(200, $response->getStatusCode());
        $tenant = $this->decode($response)['tenant'] ?? null;
        self::assertIsArray($tenant);
        self::assertTrue($tenant['is_default'] ?? null);
        self::assertSame('Acme Limited', $tenant['name'] ?? null);

        // A stranger on the bare host now sees Acme.
        $bare = $this->decode($this->request('GET', '/api/v1/public/tenant'))['tenant'] ?? null;
        self::assertIsArray($bare);
        self::assertSame('acme', $bare['slug'] ?? null);
        self::assertTrue($bare['is_default'] ?? null);
    }

    public function testAnOrganisationWithAnInvoiceKeepsItsAddress(): void
    {
        self::assertSame(200, $this->request('PATCH', '/api/v1/staff/tenants/' . $this->globex, $this->staff(), $this->json(['slug' => 'globex-corp']))->getStatusCode());

        $this->invoice($this->acme, $this->atlas);
        $refused = $this->request('PATCH', '/api/v1/staff/tenants/' . $this->acme, $this->staff(), $this->json(['slug' => 'acme-corp']));

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('TENANT_HAS_INVOICES', $this->errorOf($refused)['code'] ?? null);
        self::assertSame('acme', $this->connection->fetchOne('SELECT slug FROM tenants WHERE id = :id', ['id' => $this->acme]));
    }

    public function testOnlyStaffWhoManageTenantsMayMakeOne(): void
    {
        $response = $this->request('POST', '/api/v1/staff/tenants', ['Authorization' => 'Bearer ada-token'], $this->json(['name' => 'X', 'slug' => 'x']));

        self::assertSame(403, $response->getStatusCode());
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function staff(): array
    {
        return ['Authorization' => 'Bearer ola-token'];
    }

    private function member(string $tenantId, string $userId, string $productId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $tenantId, 'user' => $userId, 'product' => $productId],
        );
        $this->connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = :role',
            ['tenant' => $tenantId, 'user' => $userId, 'product' => $productId, 'role' => $role],
        );
    }

    private function advertise(string $productId, string $code): void
    {
        $plan = $this->id("INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 20) RETURNING id", ['product' => $productId]);
        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name, publicly_listed) VALUES (:product, :plan, :code, :code, true) RETURNING id',
            ['product' => $productId, 'plan' => $plan, 'code' => $code],
        );
        $this->connection->executeStatement(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')",
            ['offer' => $offer],
        );
    }

    private function invoice(string $tenantId, string $productId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoices (tenant_id, product_id, status, currency)
                VALUES (:tenant, :product, 'DRAFT', 'EUR')
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private function codesIn(array $body): array
    {
        $products = $body['products'] ?? null;
        self::assertIsArray($products);
        $codes = [];
        foreach ($products as $product) {
            self::assertIsArray($product);
            $code = $product['code'] ?? null;
            self::assertIsString($code);
            $codes[] = $code;
        }

        return $codes;
    }

    private function rowCount(string $sql): int
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
