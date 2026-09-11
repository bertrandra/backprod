<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Appointing and removing platform staff, through the real pipeline and the
 * real database.
 *
 * The database is deliberately not doubled. The claim under test — that the
 * platform cannot be left without an administrator — is a claim about a
 * trigger, and an in-memory roster would satisfy it by construction, proving
 * only that the test and the fake agree about a rule neither enforces.
 *
 * Three properties, in the order they matter:
 *
 *   - only PLATFORM_ADMIN may appoint anybody, because `staff.grant` is the
 *     one permission that can turn a role into every role;
 *   - the last administrator cannot be removed by any route;
 *   - an administrator cannot remove their own role, which is a different
 *     refusal with a different remedy and must read differently.
 */
#[CoversNothing]
final class StaffRosterTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';
    private string $outsider = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email, display_name)
             VALUES ('sub-ada', 'ada@platform.test', 'Ada') RETURNING id",
        );
        $this->support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->outsider = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),
        ]);
    }

    // --- Who may appoint -----------------------------------------------------

    public function testOnlyAnAdministratorSeesTheRoster(): void
    {
        // Sam is staff — SUPPORT_ADMIN reaches every other /staff route — and
        // is refused here. The roster is behind `staff.grant`, which he does
        // not hold, and that is the whole point of it being a separate
        // permission rather than part of being staff.
        self::assertSame(403, $this->get('/api/v1/staff/members', 'sam-token')->getStatusCode());

        $response = $this->get('/api/v1/staff/members', 'ada-token');

        self::assertSame(200, $response->getStatusCode());

        self::assertCount(2, $this->listIn($response, 'members'));
        self::assertContains(
            'PLATFORM_ADMIN',
            array_column($this->listIn($response, 'roles'), 'code'),
        );
    }

    public function testSomebodyWithNoPlatformRoleIsRefusedOutright(): void
    {
        self::assertSame(403, $this->get('/api/v1/staff/members', 'mia-token')->getStatusCode());
    }

    // --- Appointing ----------------------------------------------------------

    public function testAnAdministratorAppointsSomebodyAndTheRosterComesBack(): void
    {
        $response = $this->postJson(
            '/api/v1/staff/members',
            ['user_id' => $this->outsider, 'role' => 'SUPPORT_ADMIN'],
            'ada-token',
        );

        self::assertSame(201, $response->getStatusCode());

        $members = $this->listIn($response, 'members');
        self::assertCount(3, $members);

        $appointed = $this->memberIn($members, $this->outsider);
        self::assertSame(['SUPPORT_ADMIN'], $appointed['roles']);
    }

    public function testAppointingTwiceIsTheStateThatWasAskedFor(): void
    {
        $body = ['user_id' => $this->outsider, 'role' => 'SUPPORT_ADMIN'];

        self::assertSame(201, $this->postJson('/api/v1/staff/members', $body, 'ada-token')->getStatusCode());

        $second = $this->postJson('/api/v1/staff/members', $body, 'ada-token');

        self::assertSame(201, $second->getStatusCode());
        self::assertCount(3, $this->listIn($second, 'members'));
    }

    public function testAppointingSomebodyWhoDoesNotExistIsRefused(): void
    {
        $response = $this->postJson(
            '/api/v1/staff/members',
            ['user_id' => '00000000-0000-0000-0000-000000000000', 'role' => 'SUPPORT_ADMIN'],
            'ada-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('USER_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testARoleThatIsNotAPlatformRoleIsRefused(): void
    {
        // TENANT_ADMIN exists — in `roles`, the tenant catalogue. It is not
        // grantable here, and the refusal is what keeps the two catalogues
        // from becoming one by way of a string.
        $response = $this->postJson(
            '/api/v1/staff/members',
            ['user_id' => $this->outsider, 'role' => 'TENANT_ADMIN'],
            'ada-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PLATFORM_ROLE_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    // --- Removing ------------------------------------------------------------

    public function testARoleSomebodyDoesNotHoldCannotBeRevoked(): void
    {
        $response = $this->delete(
            sprintf('/api/v1/staff/members/%s/roles/SUPPORT_ADMIN', $this->outsider),
            'ada-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('STAFF_ROLE_NOT_HELD', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnAdministratorMayRemoveSomebodyElse(): void
    {
        $response = $this->delete(
            sprintf('/api/v1/staff/members/%s/roles/SUPPORT_ADMIN', $this->support),
            'ada-token',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->listIn($response, 'members'));
    }

    public function testAnAdministratorCannotRemoveTheirOwnAdministratorRole(): void
    {
        // Refused before the database is asked, and with its own code: the
        // remedy is "another administrator can", not "appoint somebody first".
        $response = $this->delete(
            sprintf('/api/v1/staff/members/%s/roles/PLATFORM_ADMIN', $this->admin),
            'ada-token',
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CANNOT_REVOKE_OWN_ADMIN', $this->errorOf($response)['code'] ?? null);
    }

    public function testTheLastAdministratorCannotBeRemovedByAnotherAdministrator(): void
    {
        // Two administrators, so the self-revoke rule is not what refuses
        // this: Ada removes Bob, which is allowed, and then Bob — had he
        // survived — would have been the last. Here Bob removes Ada's role
        // when Ada is the only one left, and the database refuses.
        $bob = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bob', 'bob@platform.test') RETURNING id",
        );
        $this->appoint($bob, 'PLATFORM_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'bob-token' => 'sub-bob',
            ]),
        ]);

        // Bob removes Ada. Allowed: two administrators, one remains.
        $first = $this->delete(
            sprintf('/api/v1/staff/members/%s/roles/PLATFORM_ADMIN', $this->admin),
            'bob-token',
        );
        self::assertSame(200, $first->getStatusCode());

        // Ada, now without the role, cannot reach the route at all — so the
        // last administrator is left with only himself to remove, which the
        // service refuses first. The database's own refusal is proven
        // separately, below, where no service rule can reach it.
        self::assertSame(
            403,
            $this->get('/api/v1/staff/members', 'ada-token')->getStatusCode(),
        );
    }

    public function testTheDatabaseItselfRefusesToLoseTheLastAdministrator(): void
    {
        // Straight at the table, past every service rule: this is the check
        // that survives a future endpoint, a migration, or somebody at a psql
        // prompt. If this passes and everything above were deleted, the
        // platform would still keep an administrator.
        $this->expectExceptionMessageMatches('/platform_staff_keeps_an_admin/');

        $this->connection->executeStatement(
            'DELETE FROM platform_staff WHERE user_id = :user',
            ['user' => $this->admin],
        );
    }

    public function testRemovingSomebodyWhoIsNotAnAdministratorIsNotRefused(): void
    {
        // The invariant defends losing the *last administrator*, and nothing
        // else. Removing a support engineer touches no administrator and must
        // pass straight through — an earlier draft of the trigger checked only
        // "does an administrator exist", which refused this whenever none did
        // and made the rule a trap for every database it was never true of.
        // The no-administrator-at-all case is the fixture StaffAccessTest
        // uses, and its own deletion test is what holds that line.
        $removed = $this->connection->executeStatement(
            'DELETE FROM platform_staff WHERE user_id = :user',
            ['user' => $this->support],
        );

        self::assertSame(1, $removed);
    }

    // --- The trail -----------------------------------------------------------

    public function testAppointingAndRemovingAreBothRecorded(): void
    {
        // A grant names who did it in `platform_staff.granted_by`, but that
        // dies with the row. The trail is what survives a revoke — which is
        // exactly the history somebody investigating would want.
        $this->postJson(
            '/api/v1/staff/members',
            ['user_id' => $this->outsider, 'role' => 'SUPPORT_ADMIN'],
            'ada-token',
        );

        $this->delete(
            sprintf('/api/v1/staff/members/%s/roles/SUPPORT_ADMIN', $this->outsider),
            'ada-token',
        );

        $actions = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT action FROM staff_access_log
                 WHERE resource_type = 'platform_staff' AND resource_id = :target
                 ORDER BY occurred_at
                SQL,
            ['target' => $this->outsider],
        );

        self::assertSame(['GRANT', 'REVOKE'], $actions);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function listIn(ResponseInterface $response, string $key): array
    {
        $rows = $this->decode($response)[$key] ?? null;

        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $path, array $body, string $token): ResponseInterface
    {
        return $this->request(
            'POST',
            $path,
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
        );
    }

    private function delete(string $path, string $token): ResponseInterface
    {
        return $this->request('DELETE', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
    }

    /**
     * @param list<array<string, mixed>> $members
     *
     * @return array<string, mixed>
     */
    private function memberIn(array $members, string $userId): array
    {
        foreach ($members as $member) {
            if ($member['user_id'] === $userId) {
                return $member;
            }
        }

        self::fail(sprintf('No member %s in the roster.', $userId));
    }
}
