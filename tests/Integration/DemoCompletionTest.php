<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Demo\Domain\DemoWorld;
use App\Demo\Service\DemoSeeder;
use App\Shared\Exceptions\ConflictException;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Completing a seeded deployment without a reset (2026-11-17), through the
 * real seeder and the real database.
 *
 * A deployment seeded before Parso existed is simulated by moving the Parso
 * the seed made out of the way — another slug, other addresses — which leaves
 * exactly the state such a deployment is in: everything the world names
 * except an organisation called `parso` and its people. Nothing is deleted,
 * because legal documents may not be, and that is also the honest picture:
 * completing must not need the old rows gone.
 */
#[CoversNothing]
final class DemoCompletionTest extends DatabaseApiTestCase
{
    public function testCompletingAddsTheMissingOrganisationWithItsWholeWorld(): void
    {
        $seeder = $this->seeder();
        self::assertTrue($seeder->seed()->holds());
        self::assertSame([], $seeder->missing(), 'a fresh world lacks nothing');

        $this->moveParsoAside(true);
        $before = $this->counts();

        self::assertSame(['parso'], $seeder->missing());

        $completed = $seeder->complete();

        self::assertSame([], $completed->failures());
        self::assertSame(['parso'], $completed->tenants);
        self::assertSame($this->parsoPeople(), $completed->people);
        self::assertSame([], $completed->keptPeople);
        self::assertContains('adds organisation Parso', $completed->summary());

        // Parso, as the world describes it: its products, its default, its
        // palette pin, its billing identity.
        $tenant = $this->id("SELECT id FROM tenants WHERE slug = 'parso'");
        self::assertSame('Parso', $this->connection->fetchOne('SELECT name FROM tenants WHERE id = :id', ['id' => $tenant]));
        self::assertSame(
            count(DemoWorld::TENANTS['parso']['holds']),
            $this->rowCount('SELECT count(*) FROM tenant_products WHERE tenant_id = :id', ['id' => $tenant]),
        );
        self::assertSame(DemoWorld::PALETTES['parso']['plan'], $this->connection->fetchOne(
            "SELECT p.palette FROM tenant_palettes p JOIN products pr ON pr.id = p.product_id WHERE p.tenant_id = :id AND pr.code = 'plan'",
            ['id' => $tenant],
        ));
        self::assertSame('Parso SAS', $this->connection->fetchOne('SELECT legal_name FROM billing_profiles WHERE tenant_id = :id', ['id' => $tenant]));

        // Its people can sign in: a credential carrying the public password,
        // under the subject a token this platform issues names.
        foreach ($this->parsoPeople() as $who) {
            $row = $this->connection->fetchAssociative(
                'SELECT u.auth_subject, u.id, c.password_hash FROM users u JOIN local_credentials c ON c.user_id = u.id WHERE u.email = :email',
                ['email' => DemoWorld::email($who)],
            );
            self::assertIsArray($row, "{$who} was not created");
            self::assertIsString($row['id']);
            self::assertSame('local:' . $row['id'], $row['auth_subject']);
            self::assertIsString($row['password_hash']);
            self::assertTrue(password_verify(DemoWorld::PASSWORD, $row['password_hash']), "{$who} cannot sign in");
        }

        // Its seats, live, each invoiced in Parso's own series from one.
        $seats = array_values(array_filter(DemoWorld::SEATS, static fn (array $seat): bool => $seat['tenant'] === 'parso'));
        self::assertSame(
            count($seats),
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE tenant_id = :id AND status = 'ACTIVE'", ['id' => $tenant]),
        );
        $numbers = array_map(static fn ($invoice): ?string => $invoice->number, $completed->invoices);
        self::assertSame(['2026-000001', '2026-000002', '2026-000003'], $numbers);
        self::assertSame(0, $this->rowCount(
            'SELECT count(*) FROM invoices WHERE tenant_id = :id AND issuer_tenant_id IS DISTINCT FROM tenant_id',
            ['id' => $tenant],
        ));
        self::assertSame(
            count($seats),
            $this->rowCount("SELECT count(*) FROM invoices WHERE issuer_tenant_id = :id AND status = 'PAID'", ['id' => $tenant]),
        );

        // Its projects, reachable by the people who made them.
        $this->signInAsParso();
        self::assertSame(
            ['Abri de jardin Parso — parcelle AE 101', 'Parcelle AE 101 — étude Parso'],
            $this->projectNames('parso-user1', 'plan'),
        );
        self::assertSame(['North wall — Parso'], $this->projectNames('parso-user2', 'atlas'));

        // And nothing else moved: every other organisation has what it had.
        $after = $this->counts();
        self::assertSame($before['others'], $after['others']);
        self::assertSame($before['products'], $after['products']);
        self::assertSame($before['offers'], $after['offers']);

        // Idempotent: a second run finds nothing missing and adds nothing.
        $again = $seeder->complete();
        self::assertTrue($again->addedNothing());
        self::assertSame([], $seeder->missing());
        self::assertSame($after, $this->counts());
    }

    public function testSomebodyWhoAlreadyHasAnAccountIsKeptWithTheirOwnPassword(): void
    {
        $seeder = $this->seeder();
        self::assertTrue($seeder->seed()->holds());

        // Only the organisation moves aside; its people keep their addresses.
        $this->moveParsoAside(false);
        $own = password_hash('a password of their own', PASSWORD_BCRYPT);
        $this->connection->executeStatement(
            'UPDATE local_credentials SET password_hash = :hash WHERE email = :email',
            ['hash' => $own, 'email' => DemoWorld::email('parso-user1')],
        );

        $completed = $seeder->complete();

        self::assertSame([], $completed->failures());
        self::assertSame([], $completed->people);
        self::assertSame($this->parsoPeople(), $completed->keptPeople);
        self::assertSame($own, $this->connection->fetchOne(
            'SELECT password_hash FROM local_credentials WHERE email = :email',
            ['email' => DemoWorld::email('parso-user1')],
        ));
        self::assertSame(1, $this->rowCount('SELECT count(*) FROM users WHERE email = :email', ['email' => DemoWorld::email('parso-user1')]));
    }

    public function testCompletingIsRefusedOnADeploymentThatIsNotTheDemonstrations(): void
    {
        $seeder = $this->seeder();
        self::assertTrue($seeder->seed()->holds());
        $this->moveParsoAside(true);
        $this->id("INSERT INTO products (code, name, active) VALUES ('real-thing', 'Real Thing', true) RETURNING id");

        self::assertSame([], $seeder->missing(), 'nothing is offered where nothing would be done');

        try {
            $seeder->complete();
            self::fail('completing a deployment with a product of its own was not refused');
        } catch (ConflictException $refused) {
            self::assertSame(DemoSeeder::NOT_A_DEMO_DEPLOYMENT, $refused->errorCode());
        }

        self::assertSame(0, $this->rowCount("SELECT count(*) FROM tenants WHERE slug = 'parso'"));
    }

    public function testCompletingIsRefusedWhereTheDemonstrationWasNeverSeeded(): void
    {
        try {
            $this->seeder()->complete();
            self::fail('completing an empty database was not refused');
        } catch (ConflictException $refused) {
            self::assertSame(DemoSeeder::NOT_A_DEMO_DEPLOYMENT, $refused->errorCode());
        }

        self::assertSame(0, $this->rowCount('SELECT count(*) FROM tenants'));
    }

    // --- Fixtures ----------------------------------------------------------------

    private function seeder(): DemoSeeder
    {
        $seeder = $this->container()->get(DemoSeeder::class);
        self::assertInstanceOf(DemoSeeder::class, $seeder);

        return $seeder;
    }

    /**
     * The deployment as it stood before Parso: its organisation under another
     * slug and, when asked, its people under other addresses.
     */
    private function moveParsoAside(bool $people): void
    {
        $this->connection->executeStatement("UPDATE tenants SET slug = 'parso-before', name = 'Parso before' WHERE slug = 'parso'");

        if (!$people) {
            return;
        }

        foreach ($this->parsoPeople() as $who) {
            $this->connection->executeStatement(
                "UPDATE users SET email = 'before-' || email WHERE email = :email",
                ['email' => DemoWorld::email($who)],
            );
            $this->connection->executeStatement(
                "UPDATE local_credentials SET email = 'before-' || email WHERE email = :email",
                ['email' => DemoWorld::email($who)],
            );
        }
    }

    /** @return list<string> */
    private function parsoPeople(): array
    {
        return array_keys(array_filter(
            DemoWorld::PEOPLE,
            static fn (array $person): bool => $person['tenants'] === ['parso'],
        ));
    }

    /**
     * @return array{others: array<string, int>, products: int, offers: int}
     */
    private function counts(): array
    {
        $others = [];

        foreach ($this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.slug,
                       (SELECT count(*) FROM tenant_members m WHERE m.tenant_id = t.id)
                     + (SELECT count(*) FROM subscriptions s WHERE s.tenant_id = t.id)
                     + (SELECT count(*) FROM invoices i WHERE i.tenant_id = t.id)
                     + (SELECT count(*) FROM payments p WHERE p.tenant_id = t.id)
                     + (SELECT count(*) FROM projects p WHERE p.tenant_id = t.id) AS rows
                  FROM tenants t
                 WHERE t.slug <> 'parso'
                 ORDER BY t.slug
                SQL,
        ) as $row) {
            self::assertIsString($row['slug']);
            self::assertIsNumeric($row['rows']);
            $others[$row['slug']] = (int) $row['rows'];
        }

        return [
            'others' => $others,
            'products' => $this->rowCount('SELECT count(*) FROM products'),
            'offers' => $this->rowCount('SELECT count(*) FROM offers'),
        ];
    }

    private function signInAsParso(): void
    {
        $subjects = [];

        foreach ($this->parsoPeople() as $who) {
            $subjects[$who] = 'local:' . $this->id('SELECT id FROM users WHERE email = :email', ['email' => DemoWorld::email($who)]);
        }

        $this->override([AuthProvider::class => new FakeAuthProvider($subjects)]);
    }

    /**
     * What the Projects screen lists for one person on one product, by name.
     *
     * @return list<string>
     */
    private function projectNames(string $who, string $product): array
    {
        $response = $this->request('GET', '/api/v1/projects', ['Authorization' => 'Bearer ' . $who, 'X-Product' => $product]);
        self::assertSame(200, $response->getStatusCode(), "{$who} could not read projects on {$product}");

        $projects = $this->decode($response)['projects'] ?? null;
        self::assertIsArray($projects);
        $names = [];

        foreach ($projects as $project) {
            self::assertIsArray($project);
            self::assertIsString($project['name'] ?? null);
            $names[] = $project['name'];
        }

        sort($names);

        return $names;
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
