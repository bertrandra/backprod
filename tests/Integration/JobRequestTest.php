<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Job\Domain\JobRepository;
use App\Job\Domain\Schedule;
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
 * `POST /jobs` is a tenant route, and `jobs.manage` is every customer
 * administrator's. Until 2026-10-26 it accepted any type with a handler and any
 * idempotency key, so one administrator could:
 *
 *   - queue the platform's dunning, renewal or expiry pass, which runs over
 *     every customer;
 *   - do it under the scheduler's own key and cancel it, and the schedule then
 *     counted the cancelled row as the day's pass — repeated daily, nothing
 *     recurring would run;
 *   - collide with another owner's pending job and be handed it, payload and
 *     result included.
 */
#[CoversNothing]
final class JobRequestTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $acme = '';
    private string $globex = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        $ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $gil = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-gil', 'gil@globex.test') RETURNING id");

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada', 'gil-token' => 'sub-gil']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->acme, $ada, $this->product, ['TENANT_ADMIN'], ['jobs.read', 'jobs.manage']),
                new TenantMembership($this->globex, $gil, $this->product, ['TENANT_ADMIN'], ['jobs.read', 'jobs.manage']),
            ]),
        ]);
    }

    public function testAPlatformPassCannotBeRequestedByATenant(): void
    {
        foreach (array_keys(Schedule::PERIODIC) as $type) {
            $response = $this->requestJob('ada-token', ['type' => $type]);

            self::assertSame(403, $response->getStatusCode(), $type);
            self::assertSame('JOB_TYPE_NOT_REQUESTABLE', $this->errorOf($response)['code'] ?? null, $type);
        }

        self::assertSame(0, $this->rows('SELECT count(*) FROM jobs'));
    }

    public function testTheSchedulesKeyIsReserved(): void
    {
        $response = $this->requestJob('ada-token', ['type' => 'export.project', 'idempotency_key' => Schedule::KEY]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('JOB_KEY_RESERVED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAKeyHeldByAnotherTenantIsRefusedAndItsJobIsNotHandedBack(): void
    {
        $first = $this->requestJob('ada-token', ['type' => 'export.project', 'idempotency_key' => 'nightly']);
        self::assertSame(202, $first->getStatusCode(), (string) $first->getBody());

        $second = $this->requestJob('gil-token', ['type' => 'export.project', 'idempotency_key' => 'nightly']);

        self::assertSame(409, $second->getStatusCode());
        self::assertSame('JOB_KEY_TAKEN', $this->errorOf($second)['code'] ?? null);
        $firstId = $this->decode($first)['id'] ?? null;
        self::assertIsString($firstId);
        self::assertStringNotContainsString($firstId, (string) $second->getBody());
    }

    public function testTheSameTenantStillGetsItsOwnPendingJobBack(): void
    {
        $first = $this->decode($this->requestJob('ada-token', ['type' => 'export.project', 'idempotency_key' => 'mine']));
        $again = $this->requestJob('ada-token', ['type' => 'export.project', 'idempotency_key' => 'mine']);

        self::assertSame(202, $again->getStatusCode());
        self::assertSame($first['id'] ?? null, $this->decode($again)['id'] ?? null);
    }

    /**
     * Even a row that somehow names a tenant under the schedule's key — an old
     * row from before this fix — does not count as the platform's pass.
     */
    public function testTheScheduleReadsOnlyItsOwnRows(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO jobs (type, payload, tenant_id, product_id, idempotency_key, status, max_attempts, finished_at)
             VALUES ('subscription.dunning', '{}', :tenant, :product, :key, 'CANCELLED', 3, now())",
            ['tenant' => $this->acme, 'product' => $this->product, 'key' => Schedule::KEY],
        );

        $jobs = $this->container()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);

        $history = $jobs->scheduleHistory(['subscription.dunning']);

        self::assertArrayNotHasKey('subscription.dunning', $history['last']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestJob(string $token, array $body): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/jobs',
            ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas', 'Content-Type' => 'application/json'],
            $this->json($body),
        );
    }

    private function rows(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);

        return is_numeric($value) ? (int) $value : -1;
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
