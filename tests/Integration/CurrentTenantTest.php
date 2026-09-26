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

/**
 * The organisation, read and renamed by its own members — and in the shape
 * the contract declares.
 *
 * The envelope is the point. `showCurrentTenant` and `updateCurrentTenant`
 * answered a bare tenant, without `may_author_offers`, from the day they
 * were written until 2026-09-17; the contract said `{tenant: …}` all along,
 * the generated client typed it that way, and the screen reading
 * `data.tenant` quietly got nothing and showed an id where the name should
 * be. No test looked at the envelope, so none failed. This one looks.
 */
#[CoversNothing]
final class CurrentTenantTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug, may_author_offers) VALUES ('Acme Ltd', 'acme', true) RETURNING id",
        );
        $admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id",
        );
        $member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-raj', 'raj@acme.test') RETURNING id",
        );

        // Boreas exists and is never assigned to Acme, which is what makes
        // "a product this organisation does not hold" a case rather than a
        // sentence (2026-09-26).
        $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada', 'raj-token' => 'sub-raj']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $admin, $this->product, ['TENANT_ADMIN'], ['tenant.read', 'tenant.manage']),
                new TenantMembership($this->tenant, $member, $this->product, ['USER'], ['tenant.read']),
            ]),
        ]);
    }

    public function testTheOrganisationIsReadInTheContractsEnvelope(): void
    {
        $response = $this->request('GET', '/api/v1/tenants/current', $this->headers('raj-token'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['tenant' => [
                'id' => $this->tenant,
                'name' => 'Acme Ltd',
                'slug' => 'acme',
                'may_author_offers' => true,
                'join_policy' => 'OPEN',
                'join_domains' => [],
                // No answer yet, which is what null means: the deployment's
                // own default decides (2026-09-26).
                'default_product' => null,
            ]],
            $this->decode($response),
        );
    }

    public function testRenamingAnswersTheSameShape(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('ada-token'),
            $this->json(['name' => 'Acme Holdings']),
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $tenant = $body['tenant'] ?? null;
        self::assertIsArray($tenant);
        self::assertSame('Acme Holdings', $tenant['name'] ?? null);
        self::assertTrue($tenant['may_author_offers'] ?? null);
        // Wrapped, not bare: the bare shape is the one that went unnoticed.
        self::assertArrayNotHasKey('name', $body);
    }

    public function testAMemberWithoutTenantManageCannotRename(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('raj-token'),
            $this->json(['name' => 'Raj Ltd']),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * An organisation names the product it opens on (2026-09-26).
     *
     * A courtesy and never an authority: it settles where a screen opens and
     * nothing about what anybody may reach there. It existed only as
     * `VITE_DEFAULT_PRODUCT`, compiled into the bundle, so a deployment that
     * leads with Plan showed whichever product sorts first until somebody
     * rebuilt it.
     */
    public function testAnOrganisationNamesTheProductItOpensOn(): void
    {
        $this->holdsAtlas();

        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('ada-token'),
            $this->json(['default_product' => 'atlas']),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('atlas', self::openingProduct($this->decode($response)));

        // Read back through the other endpoint, because one shape is the
        // point of this file.
        $read = $this->request('GET', '/api/v1/tenants/current', $this->headers('raj-token'));
        self::assertSame('atlas', self::openingProduct($this->decode($read)));
    }

    /**
     * Only a product the organisation holds. The database says so through a
     * composite key to `tenant_products`, and this is that answer read back
     * as a 400 rather than as a driver exception.
     */
    public function testAProductTheOrganisationDoesNotHoldIsRefused(): void
    {
        $this->holdsAtlas();

        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('ada-token'),
            $this->json(['default_product' => 'boreas']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
        self::assertNull(
            $this->connection->fetchOne('SELECT default_product_id FROM tenants WHERE id = :id', ['id' => $this->tenant]),
        );
    }

    /**
     * Absent leaves it alone; `null` clears it. Two different requests, and
     * a nullable read alone cannot tell them apart.
     */
    public function testAnAbsentFieldKeepsTheAnswerAndAnExplicitNullClearsIt(): void
    {
        $this->holdsAtlas();

        $this->request('PATCH', '/api/v1/tenants/current', $this->headers('ada-token'), $this->json(['default_product' => 'atlas']));

        $renamed = $this->request('PATCH', '/api/v1/tenants/current', $this->headers('ada-token'), $this->json(['name' => 'Acme Holdings']));
        self::assertSame('atlas', self::openingProduct($this->decode($renamed)));

        $cleared = $this->request('PATCH', '/api/v1/tenants/current', $this->headers('ada-token'), $this->json(['default_product' => null]));
        self::assertSame(200, $cleared->getStatusCode(), (string) $cleared->getBody());

        $tenant = $this->decode($cleared)['tenant'] ?? null;
        self::assertIsArray($tenant);
        // `assertArrayHasKey` and then the value: `?? 'sentinel'` cannot tell
        // a null apart from a missing key, which is the whole assertion here.
        self::assertArrayHasKey('default_product', $tenant);
        self::assertNull($tenant['default_product']);
    }

    /**
     * And it is the administrator's, like the name and the join policy
     * beside it.
     */
    public function testAMemberCannotNameTheOpeningProduct(): void
    {
        $this->holdsAtlas();

        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('raj-token'),
            $this->json(['default_product' => 'atlas']),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Unassigning the product clears the default rather than refusing the
     * unassignment — `ON DELETE SET NULL` on the column, not on the row. A
     * preference must not block an administrative act.
     */
    /**
     * A default that names a product the organisation no longer holds is
     * never read as one.
     *
     * Unassigning clears it in the console's own transaction, which is the
     * first of three guards; the others are the `EXISTS` on the write and
     * the shell, which only honours a code among the products the person
     * actually holds. Three, because the composite foreign key that would
     * have said it once put a cycle in the schema (the migration says why),
     * and an invariant with no single owner needs to fail safe at each end.
     */
    public function testADefaultSurvivesNothingItNoLongerHolds(): void
    {
        $this->holdsAtlas();

        $this->request('PATCH', '/api/v1/tenants/current', $this->headers('ada-token'), $this->json(['default_product' => 'atlas']));

        // Straight at the table, as a deployment that unassigned some other
        // way would leave it: the read must not obey it either.
        $this->connection->executeStatement(
            'DELETE FROM tenant_products WHERE tenant_id = :tenant AND product_id = :product',
            ['tenant' => $this->tenant, 'product' => $this->product],
        );

        $response = $this->request(
            'PATCH',
            '/api/v1/tenants/current',
            $this->headers('ada-token'),
            $this->json(['default_product' => 'atlas']),
        );

        self::assertSame(400, $response->getStatusCode(), 'Naming it again is refused once it is not held.');
    }

    /**
     * The opening product off a `{tenant: …}` envelope, narrowed.
     *
     * @param array<string, mixed> $body
     */
    private static function openingProduct(array $body): ?string
    {
        $tenant = $body['tenant'] ?? null;
        self::assertIsArray($tenant);
        self::assertArrayHasKey('default_product', $tenant);

        $code = $tenant['default_product'];
        self::assertTrue($code === null || is_string($code));

        return is_string($code) ? $code : null;
    }

    /**
     * Acme holds Atlas, which is what lets it be named as the default.
     *
     * In the tests that need it rather than in `setUp`, because most of this
     * file is about the name and the envelope and has no opinion on which
     * products the platform has assigned.
     */
    private function holdsAtlas(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_products (tenant_id, product_id) VALUES (:tenant, :product) ON CONFLICT DO NOTHING',
            ['tenant' => $this->tenant, 'product' => $this->product],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
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
