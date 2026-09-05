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
use Psr\Http\Message\ResponseInterface;

/**
 * Whether the queue is still being polled, and who may ask (R10).
 *
 * Isolation first (§37.4). The signal carries no customer data, but it is an
 * admin surface and the boundary is the permission rather than the shape of
 * what comes back — so a tenant administrator and a platform role that does
 * not hold it are both refused before anything else is asserted.
 *
 * Against the real database, because every number here is PostgreSQL
 * subtracting timestamps from one `now()`. A double would be a second
 * implementation of the arithmetic under test.
 */
#[CoversNothing]
final class AdminQueueTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $operator = '';
    private string $supporter = '';
    private string $accountant = '';
    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->operator = $this->user('sub-ops', 'ops@platform.test');
        $this->supporter = $this->user('sub-sam', 'sam@platform.test');
        $this->accountant = $this->user('sub-fin', 'fin@platform.test');
        $this->member = $this->user('sub-mia', 'mia@acme.test');

        $this->grantRole($this->operator, 'PLATFORM_ADMIN');
        $this->grantRole($this->supporter, 'SUPPORT_ADMIN');
        $this->grantRole($this->accountant, 'FINANCE_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ops-token' => 'sub-ops',
                'sam-token' => 'sub-sam',
                'fin-token' => 'sub-fin',
                'mia-token' => 'sub-mia',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->member,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage'],
                ),
            ]),
        ]);
    }

    // --- The boundary --------------------------------------------------------

    public function testATenantAdminCannotAskWhetherThePlatformsQueueIsAlive(): void
    {
        $response = $this->get('/api/v1/admin/queue', 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        $response = $this->request('GET', '/api/v1/admin/queue');

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A platform role is not a blanket pass. Finance holds the revenue
     * dashboard and not this, which is the point of naming permissions rather
     * than roles at the route.
     */
    public function testAPlatformRoleWithoutThisPermissionIsRefused(): void
    {
        self::assertSame(403, $this->get('/api/v1/admin/queue', 'fin-token')->getStatusCode());
    }

    /**
     * And support does hold it, deliberately — unlike the financial
     * dashboard. "Why has my export not arrived?" is a support question whose
     * honest answer is sometimes "the runner has not run since Tuesday".
     */
    public function testSupportMayAskBecauseTheyAreTheOnesAsked(): void
    {
        self::assertSame(200, $this->get('/api/v1/admin/queue', 'sam-token')->getStatusCode());
    }

    // --- What it says --------------------------------------------------------

    /**
     * The state most easily mistaken for health: every count is zero and
     * nothing is overdue, because nothing has ever happened.
     */
    public function testNeverHavingRunIsSaidOutLoudRatherThanLeftToInference(): void
    {
        $body = $this->queue();

        self::assertTrue($body['never_ran'] ?? null);
        self::assertNull($this->lastRun($body)['started_at']);
    }

    /**
     * No threshold, no verdict. How often cron fires is deployment
     * configuration this process does not know, and a guess here would read
     * as authoritative.
     */
    public function testWithoutAThresholdNoVerdictIsOffered(): void
    {
        $this->finishedRun(minutesAgo: 600);

        self::assertArrayNotHasKey('stale', $this->queue());
    }

    public function testNeverHavingRunIsStaleAtAnyThreshold(): void
    {
        self::assertTrue($this->queue('?stale_after=86400')['stale'] ?? null);
    }

    public function testARecentPassIsNotStaleAndAnOldOneIs(): void
    {
        $this->finishedRun(minutesAgo: 2);

        self::assertFalse($this->queue('?stale_after=600')['stale'] ?? null);

        $this->connection->executeStatement(
            "UPDATE job_runs SET started_at = now() - interval '3 hours',"
            . " finished_at = now() - interval '3 hours'",
        );

        self::assertTrue($this->queue('?stale_after=600')['stale'] ?? null);
    }

    /**
     * Two different failures, kept apart. A cron that stopped firing ages
     * `seconds_since_finished`; a runner that died mid-pass leaves a run open.
     * Collapsing them into one flag would lose which thing to go and fix.
     */
    public function testARunnerThatDiedMidPassIsDistinctFromACronThatStopped(): void
    {
        $this->finishedRun(minutesAgo: 1);
        $this->connection->executeStatement(
            "INSERT INTO job_runs (started_at, claimed) VALUES (now() - interval '2 hours', 1)",
        );

        $body = $this->queue('?stale_after=600');

        // The clock says the cron is firing.
        self::assertFalse($body['stale'] ?? null);
        // The open run says something else.
        self::assertSame(1, $body['unfinished_runs'] ?? null);
        self::assertGreaterThan(7000, $body['oldest_unfinished_seconds'] ?? 0);
    }

    /**
     * A cron firing faithfully into a wedged handler looks perfectly alive by
     * the clock, so the backlog is reported beside it. Work scheduled for
     * later is not backlog — it is not due yet.
     */
    public function testTheBacklogCountsWhatIsDueAndNotWhatIsScheduled(): void
    {
        $this->finishedRun(minutesAgo: 1);
        $this->queued(dueInMinutes: -30);
        $this->queued(dueInMinutes: -5);
        $this->queued(dueInMinutes: 60);

        $backlog = $this->queue()['backlog'] ?? null;

        self::assertIsArray($backlog);
        self::assertSame(2, $backlog['due'] ?? null);
        self::assertGreaterThan(1700, $backlog['oldest_due_seconds'] ?? 0);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function queue(string $query = ''): array
    {
        $response = $this->get('/api/v1/admin/queue' . $query, 'ops-token');

        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function lastRun(array $body): array
    {
        $run = $body['last_run'] ?? null;

        self::assertIsArray($run);

        /** @var array<string, mixed> $run */
        return $run;
    }

    private function finishedRun(int $minutesAgo): void
    {
        $this->connection->executeStatement(
            'INSERT INTO job_runs (started_at, finished_at, claimed, succeeded, failed)'
            . ' VALUES (now() - make_interval(mins => :started),'
            . ' now() - make_interval(mins => :finished), 1, 1, 0)',
            ['started' => $minutesAgo, 'finished' => $minutesAgo],
        );
    }

    private function queued(int $dueInMinutes): void
    {
        $this->connection->executeStatement(
            'INSERT INTO jobs (type, payload, priority, max_attempts, run_after)'
            . " VALUES ('demo.ok', '{}'::jsonb, 5, 3, now() + make_interval(mins => :due))",
            ['due' => $dueInMinutes],
        );
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    private function grantRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id)'
            . ' SELECT :user, id FROM platform_roles WHERE code = :role',
            ['user' => $userId, 'role' => $role],
        );
    }

    private function user(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
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
