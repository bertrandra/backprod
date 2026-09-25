<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tenant\Infrastructure\PostgresTenantMemberRepository;
use App\Tenant\Infrastructure\PostgresTenantMembershipRepository;
use App\Tests\Support\TestDatabase;
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
     * **Buying is the member's, and the administrator does not have it**
     * (2026-09-25).
     *
     * `billing.pay` went to both roles on 2026-09-18 so a stranger who had
     * just signed up could pay for what they chose; the administrator got it
     * because the role is a superset, not because anybody decided it. The
     * operator found the consequence on their own catalogue: an administrator
     * who subscribes to nothing was offered *Buy for yourself* on every
     * offer.
     *
     * It gates taking out a seat, giving one up before paying, and paying an
     * invoice through the provider. All three are the buyer's. What the
     * administrator does with money is record what arrived —
     * `billing.manage`, which is theirs and stays.
     *
     * Against the real role table, because that is the only place this rule
     * lives: every screen and every route reads the strings it produces, so a
     * test with its own fixture would prove nothing about it.
     */
    public function testBuyingIsTheMembersAndRecordingMoneyIsTheAdministrators(): void
    {
        $product = $this->seedProduct('atlas');
        $tenant = $this->seedTenant('acme');
        $admin = $this->seedUser('sub-admin', 'admin@example.test');
        $member = $this->seedUser('sub-member', 'member@example.test');

        $this->seedMember($tenant, $admin, $product, ['TENANT_ADMIN']);
        $this->seedMember($tenant, $member, $product, ['USER']);

        $memberships = new PostgresTenantMembershipRepository($this->connection);

        $administrator = $memberships->findForUserAndProduct($admin, $product)[0]->permissions;
        $buyer = $memberships->findForUserAndProduct($member, $product)[0]->permissions;

        self::assertContains('billing.pay', $buyer);
        self::assertNotContains('billing.pay', $administrator);

        // And the other half, or this would read as taking something away
        // rather than putting it where it belongs: recording a payment and
        // issuing a document are the administrator's, and a member has
        // neither.
        self::assertContains('billing.manage', $administrator);
        self::assertNotContains('billing.manage', $buyer);
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
     * The member list is scoped to the caller's tenant, so another tenant's
     * members are simply not there to be seen — and a product the tenant
     * was never given is not a place its members can appear.
     */
    public function testMemberListsAreScopedToOneTenant(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');
        $globex = $this->seedTenant('globex');

        $mine = $this->seedUser('sub-mine', 'mine@example.test');
        $theirs = $this->seedUser('sub-theirs', 'theirs@example.test');

        $this->seedMember($acme, $mine, $atlas, ['USER']);
        $this->seedMember($globex, $theirs, $beacon, ['USER']);

        $members = new PostgresTenantMemberRepository($this->connection);

        self::assertSame([$mine], array_map(static fn ($m): string => $m->userId, $members->listMembers($acme, $atlas)));
        // Acme holds atlas only: nobody is a member of it in beacon.
        self::assertSame([], $members->listMembers($acme, $beacon));
    }

    /**
     * A person is a member of the tenant, and the platform mirrors that onto
     * every product the tenant holds (ADR-047). Which product the
     * administrator was acting in when they added a colleague does not
     * decide which products that colleague may see.
     */
    public function testAMemberIsAMemberOfEveryProductTheTenantHolds(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');
        TestDatabase::assignProduct($this->connection, $acme, $beacon);

        $user = $this->seedUser('sub-both', 'both@example.test');

        // Added from atlas; present in beacon too, with the same role.
        $this->seedMember($acme, $user, $atlas, ['USER']);

        $members = new PostgresTenantMemberRepository($this->connection);

        self::assertSame(['USER'], $members->findMember($acme, $beacon, $user)?->roles);
        self::assertSame(2, $this->countRoleRowsFor($user));

        // A role change made from one product is the role in every product.
        $members->replaceRoles($acme, $beacon, $user, ['TENANT_ADMIN']);

        self::assertSame(['TENANT_ADMIN'], $members->findMember($acme, $atlas, $user)?->roles);
        self::assertSame(['TENANT_ADMIN'], $members->findMember($acme, $beacon, $user)?->roles);

        // And removal from one product is removal from the organisation.
        $members->removeMember($acme, $atlas, $user);

        self::assertNull($members->findMember($acme, $beacon, $user));
        self::assertSame(0, $this->countRoleRowsFor($user));
    }

    /**
     * Withdrawing a product from a tenant takes the memberships in it, and
     * only those — the foreign key cascades, so no service has to remember.
     */
    public function testWithdrawingAProductRemovesTheMembershipsInIt(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');
        TestDatabase::assignProduct($this->connection, $acme, $beacon);

        $user = $this->seedUser('sub-kept', 'kept@example.test');
        $this->seedMember($acme, $user, $atlas, ['USER']);

        $this->connection->executeStatement(
            'DELETE FROM tenant_products WHERE tenant_id = :tenant AND product_id = :product',
            ['tenant' => $acme, 'product' => $beacon],
        );

        $members = new PostgresTenantMemberRepository($this->connection);

        self::assertNull($members->findMember($acme, $beacon, $user));
        self::assertSame(['USER'], $members->findMember($acme, $atlas, $user)?->roles);
        self::assertSame(1, $this->countRoleRowsFor($user));
    }

    /**
     * The schema refuses a membership in a product the tenant was never
     * given, whatever wrote it.
     */
    public function testAMembershipOutsideAnAssignmentIsRefused(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $acme = $this->seedTenant('acme');
        $user = $this->seedUser('sub-stray', 'stray@example.test');
        $this->seedMember($acme, $user, $atlas, ['USER']);

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $acme, 'user' => $user, 'product' => $beacon],
        );
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
        TestDatabase::assignProduct($this->connection, $tenantId, $productId);
        (new PostgresTenantMemberRepository($this->connection))
            ->addMember($tenantId, $productId, $userId, $roles);
    }
}
