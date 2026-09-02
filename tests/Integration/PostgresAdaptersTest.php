<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Product\Infrastructure\PostgresProductRepository;
use App\Tenant\Infrastructure\PostgresTenantMemberRepository;
use App\Tenant\Infrastructure\PostgresTenantMembershipRepository;
use App\User\Infrastructure\PostgresUserDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The repository adapters, against a real database.
 *
 * These assert the things a double cannot: that the SQL is valid, that the
 * schema's constraints hold, and that a query written to be tenant-safe
 * actually is.
 */
#[CoversNothing]
final class PostgresAdaptersTest extends DatabaseTestCase
{
    public function testFirstSightProvisionsAUserAndLaterSightsReuseIt(): void
    {
        $directory = new PostgresUserDirectory($this->connection);

        $first = $directory->resolve(new AuthenticatedIdentity('sub-alice', 'alice@example.test'));
        $second = $directory->resolve(new AuthenticatedIdentity('sub-alice', 'alice@example.test'));

        self::assertSame($first->id, $second->id, 'A returning user must keep their id.');
        self::assertSame('sub-alice', $first->authSubject);
        self::assertSame(1, $this->countUsers());
    }

    /**
     * Providers let people change their email, so it is refreshed on sign-in
     * and is never the key (ADR-017).
     */
    public function testEmailIsRefreshedWithoutCreatingASecondUser(): void
    {
        $directory = new PostgresUserDirectory($this->connection);

        $before = $directory->resolve(new AuthenticatedIdentity('sub-alice', 'old@example.test'));
        $after = $directory->resolve(new AuthenticatedIdentity('sub-alice', 'new@example.test'));

        self::assertSame($before->id, $after->id);
        self::assertSame('new@example.test', $after->email);
        self::assertSame(1, $this->countUsers());
    }

    public function testProvisioningGrantsNoMembership(): void
    {
        $directory = new PostgresUserDirectory($this->connection);
        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $user = $directory->resolve(new AuthenticatedIdentity('sub-newcomer'));
        $product = $this->seedProduct('atlas');

        self::assertSame([], $memberships->findForUserAndProduct($user->id, $product));
    }

    public function testMembershipRolesSurviveTheArrayRoundTrip(): void
    {
        $directory = new PostgresUserDirectory($this->connection);
        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $user = $directory->resolve(new AuthenticatedIdentity('sub-alice'));
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $this->seedMembership($tenant, $user->id, $product, ['TENANT_ADMIN', 'USER']);

        $found = $memberships->findForUserAndProduct($user->id, $product);

        self::assertCount(1, $found);
        self::assertSame(['TENANT_ADMIN', 'USER'], $found[0]->roles);
        self::assertSame($tenant, $found[0]->tenantId);
    }

    /**
     * The isolation guarantee, asserted against the query that enforces it.
     */
    public function testOneUsersMembershipsAreNeverVisibleToAnother(): void
    {
        $directory = new PostgresUserDirectory($this->connection);
        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $alice = $directory->resolve(new AuthenticatedIdentity('sub-alice'));
        $bob = $directory->resolve(new AuthenticatedIdentity('sub-bob'));
        $product = $this->seedProduct('atlas');

        $acme = $this->seedTenant('acme');
        $globex = $this->seedTenant('globex');
        $this->seedMembership($acme, $alice->id, $product, ['TENANT_ADMIN']);
        $this->seedMembership($globex, $bob->id, $product, ['USER']);

        $forAlice = $memberships->findForUserAndProduct($alice->id, $product);
        $forBob = $memberships->findForUserAndProduct($bob->id, $product);

        self::assertSame([$acme], array_map(static fn ($m): string => $m->tenantId, $forAlice));
        self::assertSame([$globex], array_map(static fn ($m): string => $m->tenantId, $forBob));
    }

    /**
     * Membership is per product (§12.1), and the query must respect that even
     * when the same user belongs to the same tenant in another product.
     */
    public function testMembershipInAnotherProductIsNotReturned(): void
    {
        $directory = new PostgresUserDirectory($this->connection);
        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $alice = $directory->resolve(new AuthenticatedIdentity('sub-alice'));
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');

        $this->seedMembership($acme, $alice->id, $beacon, ['TENANT_ADMIN']);

        self::assertSame([], $memberships->findForUserAndProduct($alice->id, $atlas));
        self::assertCount(1, $memberships->findForUserAndProduct($alice->id, $beacon));
    }

    public function testProductLookupIsByCodeAndReportsActivity(): void
    {
        $products = new PostgresProductRepository($this->connection);

        $this->seedProduct('atlas');
        $this->seedProduct('retired', false);

        self::assertNull($products->findByCode('nope'));

        $atlas = $products->findByCode('atlas');
        self::assertNotNull($atlas);
        self::assertTrue($atlas->active);

        $retired = $products->findByCode('retired');
        self::assertNotNull($retired);
        self::assertFalse($retired->active);
    }

    private function countUsers(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM users');

        if (!is_numeric($count)) {
            self::fail('count(*) did not return a number.');
        }

        return (int) $count;
    }

    private function seedProduct(string $code, bool $active = true): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, :active) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code), 'active' => $active ? 'true' : 'false'],
        );

        self::assertIsString($id);

        return $id;
    }

    private function seedTenant(string $slug): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => ucfirst($slug), 'slug' => $slug],
        );

        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $roles
     */
    private function seedMembership(string $tenantId, string $userId, string $productId, array $roles): void
    {
        (new PostgresTenantMemberRepository($this->connection))
            ->addMember($tenantId, $productId, $userId, $roles);
    }
}
