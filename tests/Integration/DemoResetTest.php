<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Demo\Domain\DemoWorld;
use App\Demo\Service\DemoSeeder;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The demonstration world, rebuilt from the console — through the real
 * pipeline and the real database.
 *
 * **Not doubled.** The claim is that the reset empties every business table
 * and seeds the world the seeder defines, verified; that it refuses while a
 * product that is not the demo's exists; and that the caller has deleted
 * themselves. All three are what the database holds afterwards, which a fake
 * seeder could only repeat from its fixture.
 */
#[CoversNothing]
final class DemoResetTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Two staff, appointed by SQL the way the older staff tests do: the
        // reset is what deletes them, which is part of what is proven.
        $this->admin = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->support = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),
        ]);
    }

    public function testResettingSeedsTheWorldAndVerifiesIt(): void
    {
        $this->seedDemoWithLeftovers();

        $response = $this->reset('ola-token');

        self::assertSame(200, $response->getStatusCode());
        $world = $this->decode($response)['world'] ?? null;
        self::assertIsArray($world);

        // What the answer says: who to sign in as, and with what.
        self::assertSame(DemoWorld::PASSWORD, $world['password'] ?? null);
        self::assertSame(['atlas', 'boreas', 'ceres', 'delos', 'plan'], array_column($this->listIn($world, 'products'), 'code'));
        self::assertSame(
            [
                'backprod@raillard.org',
                // acme-user4 holds the Lecture seat (2026-09-23). An ordinary
                // member with an ordinary role: what makes them a reader is the
                // seat, not who they are.
                'acme-admin@raillard.org', 'acme-user1@raillard.org', 'acme-user2@raillard.org', 'acme-user4@raillard.org',
                'globex-admin@raillard.org', 'globex-user1@raillard.org', 'globex-user2@raillard.org',
                'initech-admin@raillard.org', 'initech-user1@raillard.org',
            ],
            array_column($this->listIn($world, 'people'), 'email'),
        );
        self::assertSame(['2026-000001', '2026-000002', '2026-000003', '2026-000004'], $world['invoices'] ?? null);

        // What the database holds: the world and nothing else.
        self::assertSame(5, $this->rowCount('SELECT count(*) FROM products'));
        self::assertSame(count(DemoWorld::PEOPLE), $this->rowCount('SELECT count(*) FROM users'));
        // The organisations' subscriptions, plus the seats: a seat is a
        // subscription of its own, belonging to one person (§13.1), and it
        // coexists with the tenant's rather than replacing it.
        self::assertSame(
            count(DemoWorld::SUBSCRIPTIONS) + count(DemoWorld::SEATS),
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"),
        );
        self::assertSame(
            count(DemoWorld::SEATS),
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE subscriber_kind = 'USER' AND status = 'ACTIVE'"),
        );
        // Down to the project (2026-09-22): made through the workspace, so
        // each was counted against a quota the offer actually grants and
        // stored under a schema version the product declared.
        self::assertSame(count(DemoWorld::PROJECTS), $this->rowCount('SELECT count(*) FROM projects'));
        self::assertSame(3, $this->rowCount("SELECT count(*) FROM projects p JOIN products pr ON pr.id = p.product_id WHERE pr.code = 'plan'"));
        self::assertSame('https://plan.raillard.org', $this->connection->fetchOne("SELECT app_url FROM products WHERE code = 'plan'"));
        // Where a member's screens open when no address says (2026-09-23):
        // the product beside the platform, for everybody who holds it — and
        // nothing at all for staff, who hold no membership to choose from.
        self::assertSame(
            count(DemoWorld::PEOPLE) - 1,
            $this->rowCount("SELECT count(*) FROM users u JOIN products p ON p.id = u.default_product_id WHERE p.code = 'plan'"),
        );
        self::assertSame(
            1,
            $this->rowCount('SELECT count(*) FROM users WHERE default_product_id IS NULL'),
        );
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM tenants WHERE slug = 'leftover'"));
        self::assertSame(0, $this->rowCount('SELECT count(*) FROM users WHERE id = :id', ['id' => $this->admin]));
        // Reference data is not business data.
        self::assertSame(6, $this->rowCount('SELECT count(*) FROM roles') + $this->rowCount('SELECT count(*) FROM platform_roles'));
    }

    public function testTheCallerIsSignedOutByTheirOwnReset(): void
    {
        $this->seedDemoWithLeftovers();

        self::assertSame(200, $this->reset('ola-token')->getStatusCode());

        // Their row is gone, so their token names nobody. Whether that reads
        // as "not signed in" or "not staff" depends on which link of the
        // context chain notices first; what matters is that it is refused,
        // and the page knows to show the sign-in form.
        $status = $this->request('GET', '/api/v1/staff/me', ['Authorization' => 'Bearer ola-token'])->getStatusCode();
        self::assertContains($status, [401, 403]);
    }

    public function testResettingIsRefusedWhileARealProductExists(): void
    {
        $this->seedDemoWithLeftovers();
        $this->id("INSERT INTO products (code, name, active) VALUES ('real-thing', 'Real Thing', true) RETURNING id");

        $response = $this->reset('ola-token');

        self::assertSame(409, $response->getStatusCode());
        $error = $this->decode($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(DemoSeeder::NOT_A_DEMO_DEPLOYMENT, $error['code'] ?? null);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame(['real-thing'], $details['products'] ?? null);
        // Nothing was touched: the real product, and the caller, are still there.
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM products WHERE code = 'real-thing'"));
        self::assertSame(1, $this->rowCount('SELECT count(*) FROM users WHERE id = :id', ['id' => $this->admin]));
    }

    public function testAnEmptyDatabaseCanBeReset(): void
    {
        // No product at all is not "a product that is not the demo's".
        $response = $this->reset('ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $this->rowCount('SELECT count(*) FROM products'));
    }

    public function testOnlyThePlatformAdministratorMayReset(): void
    {
        $this->seedDemoWithLeftovers();

        self::assertSame(403, $this->reset('sam-token')->getStatusCode());
        self::assertSame(401, $this->request('POST', '/api/v1/staff/demo/reset')->getStatusCode());
        // Refused before anything happened.
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM tenants WHERE slug = 'leftover'"));
    }

    // --- Fixtures ----------------------------------------------------------------

    /**
     * The demo, plus what a walk through it leaves behind: a tenant that is
     * not the demo's on one of the demo's products. Not a foreign *product* —
     * the rule is about products — so the reset must take it.
     */
    private function seedDemoWithLeftovers(): void
    {
        $atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $leftover = $this->id("INSERT INTO tenants (name, slug) VALUES ('Leftover Ltd', 'leftover') RETURNING id");
        $this->connection->executeStatement(
            'INSERT INTO tenant_products (tenant_id, product_id) VALUES (:tenant, :product)',
            ['tenant' => $leftover, 'product' => $atlas],
        );
    }

    private function reset(string $token): ResponseInterface
    {
        return $this->request('POST', '/api/v1/staff/demo/reset', ['Authorization' => 'Bearer ' . $token]);
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
     * @param array<mixed, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    private function listIn(array $source, string $key): array
    {
        $values = $source[$key] ?? null;
        self::assertIsArray($values);

        $items = [];

        foreach ($values as $value) {
            self::assertIsArray($value);
            /** @var array<string, mixed> $value */
            $items[] = $value;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);

        self::assertIsNumeric($count);

        return (int) $count;
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
}
