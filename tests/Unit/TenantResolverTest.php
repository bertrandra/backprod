<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ForbiddenException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tenant\Service\TenantResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantResolver::class)]
final class TenantResolverTest extends TestCase
{
    public function testSingleMembershipResolvesWithoutSelection(): void
    {
        $resolver = $this->resolverWith([
            new TenantMembership('t1', 'u1', 'p1', ['USER']),
        ]);

        self::assertSame('t1', $resolver->resolve('u1', 'p1', '')->tenantId);
    }

    public function testNoMembershipIsForbidden(): void
    {
        $resolver = $this->resolverWith([]);

        $this->expectException(ForbiddenException::class);
        $resolver->resolve('u1', 'p1', '');
    }

    /**
     * Membership is per product (§12.1): access to a tenant in one product
     * grants nothing in another.
     */
    public function testMembershipInAnotherProductDoesNotGrantAccess(): void
    {
        $resolver = $this->resolverWith([
            new TenantMembership('t1', 'u1', 'other-product', ['TENANT_ADMIN']),
        ]);

        $this->expectException(ForbiddenException::class);
        $resolver->resolve('u1', 'p1', '');
    }

    /**
     * Picking the first of several would make which tenant a write lands in
     * depend on row order, so the caller must choose (ADR-015).
     */
    public function testSeveralMembershipsRequireAnExplicitSelection(): void
    {
        $resolver = $this->resolverWith([
            new TenantMembership('t1', 'u1', 'p1', ['USER']),
            new TenantMembership('t2', 'u1', 'p1', ['TENANT_ADMIN']),
        ]);

        try {
            $resolver->resolve('u1', 'p1', '');
            self::fail('Expected a selection to be required.');
        } catch (ConflictException $e) {
            self::assertSame('TENANT_SELECTION_REQUIRED', $e->errorCode());
            self::assertSame(['tenants' => ['t1', 't2']], $e->details());
        }
    }

    public function testSelectionPicksTheNamedMembershipAndItsRoles(): void
    {
        $resolver = $this->resolverWith([
            new TenantMembership('t1', 'u1', 'p1', ['USER']),
            new TenantMembership('t2', 'u1', 'p1', ['TENANT_ADMIN']),
        ]);

        $membership = $resolver->resolve('u1', 'p1', 't2');

        self::assertSame('t2', $membership->tenantId);
        self::assertSame(['TENANT_ADMIN'], $membership->roles);
    }

    /**
     * A tenant the user does not belong to and a tenant that does not exist
     * must be indistinguishable, so the endpoint cannot be used to discover
     * which tenants exist.
     */
    public function testForeignAndNonexistentTenantsAreRejectedIdentically(): void
    {
        $resolver = $this->resolverWith([
            new TenantMembership('t1', 'u1', 'p1', ['USER']),
        ]);

        $foreign = $this->refusalFor($resolver, 'someone-elses-tenant');
        $absent = $this->refusalFor($resolver, 'no-such-tenant-anywhere');

        self::assertSame($foreign, $absent);
    }

    /**
     * @return array{int, string}
     */
    private function refusalFor(TenantResolver $resolver, string $selection): array
    {
        try {
            $resolver->resolve('u1', 'p1', $selection);
        } catch (ForbiddenException $e) {
            return [$e->statusCode(), $e->errorCode()];
        }

        self::fail('Expected the selection to be refused.');
    }

    /**
     * @param list<TenantMembership> $memberships
     */
    private function resolverWith(array $memberships): TenantResolver
    {
        return new TenantResolver(new InMemoryTenantMembershipRepository($memberships));
    }
}
