<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tenant\Infrastructure\PostgresTenantMemberRepository;
use App\Tenant\Infrastructure\PostgresTenantMembershipRepository;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The member and role model against a real database.
 *
 * These cover what the in-memory doubles cannot: that the join tables, the
 * aggregation and the cascades behave as the endpoints assume.
 */
#[CoversNothing]
final class MemberEndpointsTest extends DatabaseTestCase
{
    public function testAdministratorAndMemberResolveDifferentPermissions(): void
    {
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $admin = $this->seedUser('sub-admin', 'admin@example.test');
        $member = $this->seedUser('sub-member', 'member@example.test');

        $this->seedMember($tenant, $admin, $product, ['TENANT_ADMIN']);
        $this->seedMember($tenant, $member, $product, ['USER']);

        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $adminMembership = $memberships->findForUserAndProduct($admin, $product)[0];
        $plainMembership = $memberships->findForUserAndProduct($member, $product)[0];

        self::assertContains('members.manage', $adminMembership->permissions);
        self::assertContains('tenant.manage', $adminMembership->permissions);

        // The distinction that matters: a plain member may look, not change.
        self::assertContains('members.read', $plainMembership->permissions);
        self::assertNotContains('members.manage', $plainMembership->permissions);
        self::assertNotContains('tenant.manage', $plainMembership->permissions);
    }

    /**
     * A member with no roles is a member with no privileges, not a non-member.
     * The aggregation must not drop them.
     */
    public function testAMemberWithNoRolesStillResolves(): void
    {
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $user = $this->seedUser('sub-quiet', 'quiet@example.test');

        $this->seedMember($tenant, $user, $product, []);

        $memberships = (new PostgresTenantMembershipRepository($this->connection))
            ->findForUserAndProduct($user, $product);

        self::assertCount(1, $memberships);
        self::assertSame([], $memberships[0]->roles);
        self::assertSame([], $memberships[0]->permissions);
    }

    public function testRolesAreReplacedRatherThanMerged(): void
    {
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $user = $this->seedUser('sub-both', 'both@example.test');
        $this->seedMember($tenant, $user, $product, ['TENANT_ADMIN', 'USER']);

        $members = new PostgresTenantMemberRepository($this->connection);
        $members->replaceRoles($tenant, $product, $user, ['USER']);

        $member = $members->findMember($tenant, $product, $user);
        self::assertNotNull($member);
        self::assertSame(['USER'], $member->roles);
    }

    public function testRemovingAMemberTakesTheirRoleAssignmentsWithThem(): void
    {
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $user = $this->seedUser('sub-going', 'going@example.test');
        $this->seedMember($tenant, $user, $product, ['TENANT_ADMIN']);

        $members = new PostgresTenantMemberRepository($this->connection);
        $members->removeMember($tenant, $product, $user);

        self::assertNull($members->findMember($tenant, $product, $user));
        self::assertSame(0, $this->countRoleRowsFor($user));
    }

    /**
     * The member list is scoped to the caller's tenant and product, so
     * another tenant's members are simply not there to be seen.
     */
    public function testMemberListsAreScopedToOneTenantAndProduct(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');
        $globex = $this->seedTenant('globex');

        $mine = $this->seedUser('sub-mine', 'mine@example.test');
        $theirs = $this->seedUser('sub-theirs', 'theirs@example.test');
        $otherProduct = $this->seedUser('sub-other', 'other@example.test');

        $this->seedMember($acme, $mine, $atlas, ['USER']);
        $this->seedMember($globex, $theirs, $atlas, ['USER']);
        $this->seedMember($acme, $otherProduct, $beacon, ['USER']);

        $listed = (new PostgresTenantMemberRepository($this->connection))->listMembers($acme, $atlas);

        self::assertSame([$mine], array_map(static fn ($m): string => $m->userId, $listed));
    }

    public function testKnownRolesComeFromTheDatabase(): void
    {
        $roles = (new PostgresTenantMemberRepository($this->connection))->knownRoleCodes();

        self::assertSame(['TENANT_ADMIN', 'USER'], $roles);
    }

    public function testAdministratorsAreCountedPerTenantAndProduct(): void
    {
        $product = $this->seedProduct('atlas');
        $acme = $this->seedTenant('acme');
        $globex = $this->seedTenant('globex');

        $this->seedMember($acme, $this->seedUser('sub-a', 'a@example.test'), $product, ['TENANT_ADMIN']);
        $this->seedMember($globex, $this->seedUser('sub-b', 'b@example.test'), $product, ['TENANT_ADMIN']);

        $members = new PostgresTenantMemberRepository($this->connection);

        // One each: another tenant's administrator must not make this one
        // look safe to demote.
        self::assertSame(1, $members->countMembersWithRole($acme, $product, 'TENANT_ADMIN'));
        self::assertSame(1, $members->countMembersWithRole($globex, $product, 'TENANT_ADMIN'));
    }

    private function countRoleRowsFor(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM tenant_member_roles WHERE user_id = :id',
            ['id' => $userId],
        );

        if (!is_numeric($count)) {
            self::fail('count(*) did not return a number.');
        }

        return (int) $count;
    }

    private function seedUser(string $subject, string $email): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );

        self::assertIsString($id);

        return $id;
    }

    private function seedProduct(string $code): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO products (code, name) VALUES (:code, :name) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
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
    private function seedMember(string $tenantId, string $userId, string $productId, array $roles): void
    {
        (new PostgresTenantMemberRepository($this->connection))
            ->addMember($tenantId, $productId, $userId, $roles);
    }
}
