<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The admin boundary and the trail behind it (§30, non-negotiable #19).
 *
 * M8's exit criterion is that a `TENANT_ADMIN` provably cannot read
 * cross-tenant data, so that is asserted first and from the most privileged
 * thing a customer can be. Isolation before behaviour (§37.4): a trail that
 * works is worth nothing if the wrong people can read it.
 *
 * The second boundary is newer and easier to miss. `/admin` and `/staff` are
 * the same identity model — a platform role — and what separates them is the
 * permission each endpoint demands. So a support operator, who is genuinely
 * platform staff, must still be refused the audit trail; holding a platform
 * role is not holding every platform permission.
 */
#[CoversNothing]
final class AdminAuditTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenantA = '';
    private string $tenantB = '';
    private string $operator = '';
    private string $supporter = '';
    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenantA = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->tenantB = $this->id("INSERT INTO tenants (name, slug) VALUES ('Beta', 'beta') RETURNING id");

        $this->operator = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ops', 'ops@platform.test') RETURNING id",
        );
        $this->supporter = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );

        $this->grantRole($this->operator, 'PLATFORM_ADMIN');
        $this->grantRole($this->supporter, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ops-token' => 'sub-ops',
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            // Mia is a tenant administrator — the most privileged a customer
            // gets — and holds no platform role at all.
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenantA,
                    $this->member,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage', 'members.read', 'members.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary --------------------------------------------------------

    public function testATenantAdminCannotReadTheAuditTrail(): void
    {
        $response = $this->get('/api/v1/admin/audit', 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    /**
     * Naming a tenant in the query does not make it readable. The refusal
     * has to come from the role, not from which rows were asked for.
     */
    public function testATenantAdminCannotReadTheTrailOfTheirOwnTenantEither(): void
    {
        $response = $this->get('/api/v1/admin/audit?tenant_id=' . $this->tenantA, 'mia-token');

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Platform staff, but the wrong staff. A support operator holds a
     * platform role and is refused all the same, because the permission is
     * what authorises and SUPPORT_ADMIN was never granted this one.
     */
    public function testSupportStaffCannotReadTheAuditTrail(): void
    {
        $response = $this->get('/api/v1/admin/audit', 'sam-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnUnauthenticatedRequestIsRefusedBeforeAnythingElse(): void
    {
        $response = $this->request('GET', '/api/v1/admin/audit');

        self::assertSame(401, $response->getStatusCode());
    }

    // --- The trail -----------------------------------------------------------

    public function testAnOperatorReadsTheTrailMostRecentFirst(): void
    {
        $this->record('tenant.created', 'tenant', $this->tenantA, 'req-1');
        $this->record('subscription.cancelled', 'subscription', $this->tenantA, 'req-2');

        $body = $this->decode($this->get('/api/v1/admin/audit', 'ops-token'));

        self::assertSame(2, $body['total'] ?? null);

        $entries = $body['entries'] ?? null;
        self::assertIsArray($entries);

        $actions = array_map(
            static fn (mixed $entry): mixed => is_array($entry) ? ($entry['action'] ?? null) : null,
            $entries,
        );
        self::assertSame(['subscription.cancelled', 'tenant.created'], $actions);
    }

    /**
     * §30's reason for `request_id` being a column: "everything that happened
     * in this one request" is the question an incident is followed with.
     */
    public function testTheTrailNarrowsToOneRequest(): void
    {
        $this->record('a.happened', 'thing', $this->tenantA, 'req-1');
        $this->record('b.happened', 'thing', $this->tenantB, 'req-1');
        $this->record('c.happened', 'thing', $this->tenantA, 'req-2');

        $body = $this->decode($this->get('/api/v1/admin/audit?request_id=req-1', 'ops-token'));

        self::assertSame(2, $body['total'] ?? null);
    }

    public function testTheTrailNarrowsToOneTenant(): void
    {
        $this->record('a.happened', 'thing', $this->tenantA, 'req-1');
        $this->record('b.happened', 'thing', $this->tenantB, 'req-2');

        $body = $this->decode($this->get('/api/v1/admin/audit?tenant_id=' . $this->tenantB, 'ops-token'));

        self::assertSame(1, $body['total'] ?? null);
    }

    /**
     * A tenant id of the wrong shape is nothing to be found, not a 500. The
     * repository answers with what the database would have answered.
     */
    public function testAMalformedTenantFilterFindsNothingRatherThanFailing(): void
    {
        $this->record('a.happened', 'thing', $this->tenantA, 'req-1');

        $response = $this->get('/api/v1/admin/audit?tenant_id=not-a-uuid', 'ops-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->decode($response)['total'] ?? null);
    }

    /**
     * An erased actor and an act nobody performed both leave `user_id` null,
     * and the presenter has to keep them apart — otherwise every erasure
     * quietly reads as a system action.
     */
    public function testAForgottenActorIsReportedAsForgottenRatherThanAbsent(): void
    {
        $this->record('staff.viewed', 'tenant', $this->tenantA, 'req-1', $this->supporter);
        $this->record('system.swept', 'platform', null, 'req-2');

        $this->connection->executeStatement(
            'UPDATE audit_log SET user_id = NULL, actor_forgotten_at = now() WHERE action = :action',
            ['action' => 'staff.viewed'],
        );

        $body = $this->decode($this->get('/api/v1/admin/audit', 'ops-token'));
        $entries = $body['entries'] ?? null;
        self::assertIsArray($entries);

        $forgotten = [];

        foreach ($entries as $entry) {
            self::assertIsArray($entry);

            $action = $entry['action'] ?? null;
            $actor = $entry['actor'] ?? null;
            self::assertIsArray($actor);
            self::assertIsString($action);

            $forgotten[$action] = $actor['forgotten'] ?? null;
        }

        self::assertTrue($forgotten['staff.viewed'] ?? null);
        self::assertFalse($forgotten['system.swept'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    private function record(
        string $action,
        string $subjectType,
        ?string $tenantId,
        string $requestId,
        ?string $userId = null,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO audit_log (action, subject_type, tenant_id, request_id, user_id)
                VALUES (:action, :subjectType, :tenantId, :requestId, :userId)
                SQL,
            [
                'action' => $action,
                'subjectType' => $subjectType,
                'tenantId' => $tenantId,
                'requestId' => $requestId,
                'userId' => $userId,
            ],
        );
    }

    private function grantRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
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
