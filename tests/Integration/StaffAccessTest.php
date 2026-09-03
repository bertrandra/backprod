<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Staff\Domain\PlatformRole;
use App\Staff\Domain\StaffPermission;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §12.2's boundary, through the real pipeline and the real database.
 *
 * Isolation is tested before behaviour, because the risk this milestone
 * carries is not that staff routes fail to work — it is that they work for
 * the wrong person. A staff surface that returns the right data to the right
 * operator and also returns it to a tenant admin has not half-succeeded.
 *
 * The membership repository is a double; the staff repository deliberately is
 * not. "A platform role is not a membership" is a claim about two SQL queries
 * against two sets of tables, and an in-memory staff repository would satisfy
 * it by construction — proving only that the test and the fake agree.
 */
#[CoversNothing]
final class StaffAccessTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenantA = '';
    private string $tenantB = '';
    private string $staffUser = '';
    private string $memberUser = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenantA = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->tenantB = $this->id("INSERT INTO tenants (name, slug) VALUES ('Beta', 'beta') RETURNING id");

        $this->staffUser = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->memberUser = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = 'SUPPORT_ADMIN'
                SQL,
            ['user' => $this->staffUser],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            // Mia is a tenant administrator — the most privileged thing a
            // customer can be — and holds no platform role.
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenantA,
                    $this->memberUser,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage', 'members.read', 'members.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary --------------------------------------------------------

    public function testATenantAdminCannotReachAStaffRoute(): void
    {
        // The whole milestone in one assertion. TENANT_ADMIN is the
        // customer's administrator; it grants nothing across customers.
        $response = $this->get('/api/v1/staff/tenants', 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAStaffRoleOpensNoTenantRoute(): void
    {
        // Sam holds SUPPORT_ADMIN and belongs to no tenant. The tenant route
        // refuses him for the reason it refuses any stranger — no membership
        // — rather than consulting his platform role at all.
        $response = $this->get('/api/v1/tenants/current', 'sam-token');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnUnauthenticatedRequestIsRefusedBeforeAnythingElse(): void
    {
        $response = $this->request('GET', '/api/v1/staff/tenants', []);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAStaffRouteRefusesAUserWhoHoldsNoPlatformRole(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM platform_staff WHERE user_id = :user',
            ['user' => $this->staffUser],
        );

        $response = $this->get('/api/v1/staff/me', 'sam-token');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAPermissionOutsideTheRoleIsRefused(): void
    {
        // SUPPORT_ADMIN may read tenants; it may not read the access trail.
        // The route exists and the caller is staff — the refusal is about
        // this permission and nothing else.
        $response = $this->get('/api/v1/staff/access-log', 'sam-token');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testATenantPermissionCodeIsNotReachableFromAPlatformRole(): void
    {
        // The separation as the database enforces it: no code a tenant role
        // can grant is present in the catalogue a platform role draws from.
        self::assertSame(0, $this->rowsMatching(
            <<<'SQL'
                SELECT count(*)
                  FROM platform_permissions
                 WHERE code IN (SELECT code FROM permissions)
                SQL,
        ));
    }

    // --- What staff may actually do ------------------------------------------

    public function testStaffCanSeeWhatTheyHold(): void
    {
        $response = $this->get('/api/v1/staff/me', 'sam-token');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame($this->staffUser, $body['user_id'] ?? null);
        self::assertSame([PlatformRole::SUPPORT_ADMIN], $body['roles'] ?? null);

        $permissions = $body['permissions'] ?? null;
        self::assertIsArray($permissions);
        self::assertContains(StaffPermission::TENANTS_READ, $permissions);
        self::assertNotContains(StaffPermission::ACCESS_LOG_READ, $permissions);
    }

    public function testStaffCanListEveryTenant(): void
    {
        $response = $this->get('/api/v1/staff/tenants', 'sam-token');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame(2, $body['total'] ?? null);

        $tenants = $body['tenants'] ?? null;
        self::assertIsArray($tenants);
        self::assertCount(2, $tenants);
    }

    public function testStaffCanReadOneTenantByIdFromThePath(): void
    {
        $response = $this->get('/api/v1/staff/tenants/' . $this->tenantB, 'sam-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Beta', $this->decode($response)['name'] ?? null);
    }

    public function testAnUnknownTenantIsNotFound(): void
    {
        $response = $this->get(
            '/api/v1/staff/tenants/99999999-9999-9999-9999-999999999999',
            'sam-token',
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Non-negotiable #21: never silent ------------------------------------

    public function testReadingATenantRecordsWhoLookedAtIt(): void
    {
        $this->get('/api/v1/staff/tenants/' . $this->tenantB, 'sam-token');

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT staff_user_id, tenant_id, action, resource_type, resource_id, permission
                  FROM staff_access_log
                 WHERE resource_type = 'tenant' AND action = 'READ'
                SQL,
        );

        self::assertIsArray($row);
        self::assertSame($this->staffUser, $row['staff_user_id'] ?? null);
        self::assertSame($this->tenantB, $row['tenant_id'] ?? null);
        self::assertSame($this->tenantB, $row['resource_id'] ?? null);
        // On what grounds — the question an access log without it cannot
        // answer.
        self::assertSame(StaffPermission::TENANTS_READ, $row['permission'] ?? null);
    }

    public function testEnumeratingTenantsIsRecordedOnceAndNamesNoTenant(): void
    {
        $this->get('/api/v1/staff/tenants', 'sam-token');

        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM staff_access_log WHERE action = 'LIST' AND resource_type = 'tenant'",
        ));

        // A row per result would bury the fact under its own results.
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM staff_access_log WHERE resource_type = 'tenant' AND tenant_id IS NULL",
        ));
    }

    public function testAFailedLookupIsRecordedToo(): void
    {
        // A trail holding only successes cannot show somebody probing for ids.
        $this->get('/api/v1/staff/tenants/99999999-9999-9999-9999-999999999999', 'sam-token');

        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM staff_access_log WHERE action = 'READ_MISS'",
        ));
    }

    public function testARefusedStaffRequestReadsNothingAndRecordsNothing(): void
    {
        // Mia is refused at the policy, before any service runs. Nothing was
        // read, so there is nothing to record — an audit row here would claim
        // an access that never happened.
        $this->get('/api/v1/staff/tenants', 'mia-token');

        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM staff_access_log'));
    }

    public function testTheTrailIsReadableByARoleThatMayReadIt(): void
    {
        $this->get('/api/v1/staff/tenants/' . $this->tenantA, 'sam-token');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'
                SQL,
            ['user' => $this->staffUser],
        );

        $response = $this->get('/api/v1/staff/access-log?tenant_id=' . $this->tenantA, 'sam-token');

        self::assertSame(200, $response->getStatusCode());

        $entries = $this->decode($response)['entries'] ?? null;
        self::assertIsArray($entries);
        self::assertCount(1, $entries);

        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame('READ', $first['action'] ?? null);
        self::assertSame($this->tenantA, $first['tenant_id'] ?? null);
    }

    public function testReadingTheTrailIsItselfRecorded(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'
                SQL,
            ['user' => $this->staffUser],
        );

        $this->get('/api/v1/staff/access-log', 'sam-token');

        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM staff_access_log WHERE resource_type = 'staff_access_log'",
        ));
    }

    public function testSeeingOnesOwnRolesRecordsNothing(): void
    {
        // #21 is about crossing the boundary. Telling somebody what they
        // themselves hold crosses nothing, and a row for it would be noise in
        // the one table that must stay worth reading.
        $this->get('/api/v1/staff/me', 'sam-token');

        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM staff_access_log'));
    }

    // --- Helpers -------------------------------------------------------------

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
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
