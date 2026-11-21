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

    /**
     * A release that teaches the page something reaches a deployment that is
     * never reset (2026-11-21).
     *
     * The world as an older deployment holds it: Plan's page written before
     * the bands that sell existed, its headline heading saying nothing about
     * a layout. Completing brings exactly what was never said.
     */
    public function testCompletingBringsThePageWhatTheWorldGainedAndNothingElse(): void
    {
        $seeder = $this->seeder();
        self::assertTrue($seeder->seed()->holds());

        $plan = $this->id("SELECT id::text FROM products WHERE code = 'plan'");
        $rows = fn (string $block): int => $this->rowCount(
            'SELECT count(*) FROM product_showcase WHERE product_id = :product AND block = :block',
            ['product' => $plan, 'block' => $block],
        );

        // The page as it was before 2026-11-20.
        $this->connection->executeStatement(
            "DELETE FROM product_showcase WHERE product_id = :product AND block IN ('TRUST', 'STATS', 'FEATURE', 'CTA')",
            ['product' => $plan],
        );
        $this->connection->executeStatement(
            "DELETE FROM product_showcase_bands WHERE product_id = :product AND block IN ('TRUST', 'STATS', 'FEATURE', 'QUOTE')",
            ['product' => $plan],
        );
        $this->connection->executeStatement(
            "UPDATE product_showcase_bands SET content = content - 'layout' WHERE product_id = :product AND block = 'HEADLINE'",
            ['product' => $plan],
        );

        // And something an operator wrote since, which must survive: their
        // own title on the steps, and a step of their own.
        $this->connection->executeStatement(
            "UPDATE product_showcase_bands SET content = jsonb_build_object('title', 'Ours') WHERE product_id = :product AND block = 'STEPS'",
            ['product' => $plan],
        );
        $steps = $rows('STEPS');

        $missing = $seeder->missing();

        self::assertContains('story:plan:rows:TRUST', $missing);
        self::assertContains('story:plan:rows:CTA', $missing);
        self::assertContains('story:plan:heading:STATS', $missing);
        self::assertContains('story:plan:layout:HEADLINE', $missing);
        // A band that has rows is offered none, whatever the world says of it.
        self::assertNotContains('story:plan:rows:STEPS', $missing);
        self::assertNotContains('story:plan:heading:STEPS', $missing);

        $completed = $seeder->complete();

        self::assertSame([], $completed->failures());
        self::assertSame([], $completed->tenants, 'no organisation was missing, so none was added');
        self::assertSame($missing, $completed->story);
        self::assertFalse($completed->addedNothing());
        self::assertContains('adds the CTA band to Plan\'s page (1 row)', $completed->summary());
        self::assertContains('lays out the HEADLINE band of Plan\'s page as "stacked"', $completed->summary());

        $expected = static fn (string $block): int => count(array_filter(
            DemoWorld::SHOWCASE['plan'],
            static fn (array $row): bool => $row['block'] === $block,
        ));

        foreach (['TRUST', 'STATS', 'FEATURE', 'CTA'] as $block) {
            self::assertSame($expected($block), $rows($block), $block);
        }

        // Every new row says itself in the four other languages, like the
        // ones a seed writes.
        self::assertSame(0, $this->rowCount(
            <<<'SQL'
            SELECT count(*) FROM product_showcase s
             WHERE s.product_id = :product
               AND (SELECT count(*) FROM product_showcase_translations t WHERE t.block_id = s.id) <> 4
            SQL,
            ['product' => $plan],
        ));

        self::assertSame('stacked', $this->id(
            "SELECT content ->> 'layout' FROM product_showcase_bands WHERE product_id = :product AND block = 'HEADLINE'",
            ['product' => $plan],
        ));

        // What the operator wrote is as they wrote it.
        self::assertSame($steps, $rows('STEPS'));
        self::assertSame('Ours', $this->id(
            "SELECT content ->> 'title' FROM product_showcase_bands WHERE product_id = :product AND block = 'STEPS'",
            ['product' => $plan],
        ));

        // Idempotent: nothing is missing, and a second run adds nothing.
        self::assertSame([], $seeder->missing());
        self::assertTrue($seeder->complete()->addedNothing());
        self::assertSame($expected('FEATURE'), $rows('FEATURE'));
    }

    /**
     * A layout somebody chose is not replaced by the world's.
     */
    public function testALayoutSomebodyChoseIsLeftAlone(): void
    {
        $seeder = $this->seeder();
        self::assertTrue($seeder->seed()->holds());

        $plan = $this->id("SELECT id::text FROM products WHERE code = 'plan'");

        $this->connection->executeStatement(
            "UPDATE product_showcase_bands SET content = content || jsonb_build_object('layout', 'centered') WHERE product_id = :product AND block = 'HEADLINE'",
            ['product' => $plan],
        );

        self::assertSame([], $seeder->missing());
        self::assertTrue($seeder->complete()->addedNothing());
        self::assertSame('centered', $this->id(
            "SELECT content ->> 'layout' FROM product_showcase_bands WHERE product_id = :product AND block = 'HEADLINE'",
            ['product' => $plan],
        ));
    }

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
