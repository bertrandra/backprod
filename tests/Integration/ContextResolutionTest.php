<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\User\Domain\UserDirectory;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\InMemoryUserDirectory;
use App\User\Infrastructure\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The §10.6 chain, exercised end to end through the production wiring.
 *
 * These are the tests M1 exists for: they assert that tenant context is
 * derived, never accepted, and that the chain refuses to hand a request to a
 * handler until every stage has answered.
 */
#[CoversNothing]
final class ContextResolutionTest extends ApiTestCase
{
    private const ALICE = 'user-alice';
    private const BOB = 'user-bob';

    protected function setUp(): void
    {
        parent::setUp();

        $this->override([
            // Provisioned ids are the subjects, so fixtures stay readable.
            UserDirectory::class => new InMemoryUserDirectory(),

            UserRepository::class => new InMemoryUserRepository([
                new PlatformUser(self::ALICE, self::ALICE, 'alice@example.test'),
                new PlatformUser(self::BOB, self::BOB, 'bob@example.test'),
            ]),

            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => self::ALICE,
                'bob-token' => self::BOB,
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product('prod-atlas', 'atlas', 'Atlas', true),
                new Product('prod-retired', 'retired', 'Retired', false),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership('tenant-acme', self::ALICE, 'prod-atlas', ['TENANT_ADMIN'], ['members.manage']),
                new TenantMembership('tenant-globex', self::BOB, 'prod-atlas', ['USER'], ['members.read']),
            ]),

            EntitlementRepository::class => new InMemoryEntitlementRepository([
                'tenant-acme:prod-atlas' => ['projects.read', 'projects.write'],
                'tenant-globex:prod-atlas' => ['projects.read'],
            ]),
        ]);
    }

    public function testResolvedContextIsReportedByMe(): void
    {
        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'atlas',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'user_id' => self::ALICE,
            'email' => 'alice@example.test',
            'display_name' => null,
            'product_id' => 'prod-atlas',
            'tenant_id' => 'tenant-acme',
            'roles' => ['TENANT_ADMIN'],
            'permissions' => ['members.manage'],
            'capabilities' => ['projects.read', 'projects.write'],
        ], $this->decode($response));
    }

    /**
     * The rule from CLAUDE.md and ADR-015: a client-supplied tenant id is not
     * an input. Alice belongs to tenant-acme, so however she spells
     * "tenant-globex" the answer must still be tenant-acme.
     *
     * @param array<string, string> $extraHeaders
     */
    #[DataProvider('forgeryAttempts')]
    public function testClientSuppliedTenantIdIsIgnored(string $path, array $extraHeaders): void
    {
        $response = $this->request('GET', $path, [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'atlas',
        ] + $extraHeaders);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tenant-acme', $this->decode($response)['tenant_id'] ?? null);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function forgeryAttempts(): iterable
    {
        yield 'query parameter' => ['/api/v1/me?tenant_id=tenant-globex', []];
        yield 'unrelated header' => ['/api/v1/me', ['X-Tenant-Id' => 'tenant-globex']];
        yield 'both' => ['/api/v1/me?tenant_id=tenant-globex', ['X-Tenant-Id' => 'tenant-globex']];
    }

    /**
     * X-Tenant selects among memberships; naming a tenant the user does not
     * belong to must be refused, and must look exactly like naming one that
     * does not exist.
     */
    public function testSelectingAForeignTenantIsRefused(): void
    {
        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'atlas',
            'X-Tenant' => 'tenant-globex',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('NO_TENANT_ACCESS', $this->errorOf($response)['code'] ?? null);
    }

    public function testTwoUsersOfTheSameProductGetDifferentTenants(): void
    {
        $headers = ['X-Product' => 'atlas'];

        $alice = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer alice-token'] + $headers);
        $bob = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer bob-token'] + $headers);

        self::assertSame('tenant-acme', $this->decode($alice)['tenant_id'] ?? null);
        self::assertSame('tenant-globex', $this->decode($bob)['tenant_id'] ?? null);
        self::assertSame(['projects.read'], $this->decode($bob)['capabilities'] ?? null);
    }

    public function testMissingCredentialIsRejectedBeforeAnythingElse(): void
    {
        $response = $this->request('GET', '/api/v1/me', ['X-Product' => 'atlas']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $this->errorOf($response)['code'] ?? null);
    }

    public function testMalformedAuthorizationHeaderIsRejected(): void
    {
        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'alice-token',
            'X-Product' => 'atlas',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Authentication precedes product resolution (§10.6), so an anonymous
     * caller must not be able to probe which product codes exist.
     */
    public function testUnknownProductIsNotDisclosedToAnAnonymousCaller(): void
    {
        $response = $this->request('GET', '/api/v1/me', ['X-Product' => 'does-not-exist']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testMissingProductHeaderIsRejected(): void
    {
        $response = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer alice-token']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('PRODUCT_CONTEXT_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    public function testUnknownProductIsRejected(): void
    {
        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'nope',
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PRODUCT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testInactiveProductIsIndistinguishableFromAnUnknownOne(): void
    {
        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'retired',
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PRODUCT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testUserWithoutMembershipForTheProductIsRefused(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([]),
        ]);

        $response = $this->request('GET', '/api/v1/me', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'atlas',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('NO_TENANT_ACCESS', $this->errorOf($response)['code'] ?? null);
    }

    public function testHealthRemainsReachableWithoutAnyContext(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->decode($response));
    }
}
