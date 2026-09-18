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
            ['tenant' => ['id' => $this->tenant, 'name' => 'Acme Ltd', 'slug' => 'acme', 'may_author_offers' => true, 'join_policy' => 'OPEN', 'join_domains' => []]],
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
