<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The public demonstration page (2026-09-18): a switch the platform
 * administrator holds, and a read anybody may make while it is on.
 *
 * What it shows is a membership's answer everywhere else, so the claims
 * are about the switch as much as the contents: off is a 404 that looks
 * like no route at all, on is the whole picture, and only PLATFORM_ADMIN
 * moves it.
 */
#[CoversNothing]
final class DemoPageTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $acme = '';
    private string $globex = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->id("INSERT INTO products (code, name, active) VALUES ('comet', 'Comet', false) RETURNING id");

        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug, join_policy) VALUES ('Globex', 'globex', 'APPROVAL') RETURNING id");
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);
        TestDatabase::assignProduct($this->connection, $this->acme, $boreas);
        TestDatabase::assignProduct($this->connection, $this->globex, $this->atlas);
        $this->connection->executeStatement(
            "INSERT INTO platform_settings (key, value) VALUES ('default_tenant', CAST(:v AS jsonb))",
            ['v' => json_encode(['tenant_id' => $this->acme])],
        );

        // Ada administers Acme on both products; Grace is a USER of Acme; Zed
        // is waiting at Globex and must not appear.
        $ada = $this->person('sub-ada', 'ada@acme.test', 'Ada Lovelace');
        $grace = $this->person('sub-grace', 'grace@acme.test', 'Grace Hopper');
        $zed = $this->person('sub-zed', 'zed@elsewhere.test', 'Zed');
        foreach ([$this->atlas, $boreas] as $product) {
            $this->member($this->acme, $ada, $product, 'TENANT_ADMIN', 'ACTIVE');
            $this->member($this->acme, $grace, $product, 'USER', 'ACTIVE');
        }
        $this->member($this->globex, $zed, $this->atlas, 'USER', 'PENDING');

        // Atlas: a Pro plan with one offer on sale (advertised) and one
        // whose only version is a draft (not on sale).
        $plan = $this->id("INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 20) RETURNING id", ['p' => $this->atlas]);
        $sold = $this->id("INSERT INTO offers (product_id, plan_id, code, name, publicly_listed) VALUES (:p, :plan, 'pro-monthly', 'Pro monthly', true) RETURNING id", ['p' => $this->atlas, 'plan' => $plan]);
        $draft = $this->id("INSERT INTO offers (product_id, plan_id, code, name) VALUES (:p, :plan, 'pro-yearly', 'Pro yearly') RETURNING id", ['p' => $this->atlas, 'plan' => $plan]);
        $version = $this->id(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:o, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day') RETURNING id",
            ['o' => $sold],
        );
        $this->id(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:o, 1, 'DRAFT', 'YEARLY', 29000, 'EUR', now()) RETURNING id",
            ['o' => $draft],
        );
        // Acme subscribes to it.
        $this->connection->executeStatement(
            "INSERT INTO subscriptions (tenant_id, product_id, offer_version_id, status, current_period_start, current_period_end) VALUES (:t, :p, :v, 'ACTIVE', now(), now() + interval '30 days')",
            ['t' => $this->acme, 'p' => $this->atlas, 'v' => $version],
        );

        $ola = $this->person('sub-ola', 'ola@platform.test', 'Ola');
        $sam = $this->person('sub-sam', 'sam@platform.test', 'Sam');
        foreach ([[$ola, 'PLATFORM_ADMIN'], [$sam, 'SUPPORT_ADMIN']] as [$user, $role]) {
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam', 'ada-token' => 'sub-ada']),
        ]);
    }

    public function testOffByDefaultAndIndistinguishableFromNoRoute(): void
    {
        $response = $this->request('GET', '/api/v1/public/demo');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('DEMO_PAGE_OFF', $this->errorOf($response)['code'] ?? null);

        $switch = $this->decode($this->request('GET', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer ola-token']));
        self::assertFalse($switch['published'] ?? null);
    }

    public function testSwitchedOnItShowsTheWholePictureToAnybody(): void
    {
        $set = $this->request('PUT', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer ola-token'], $this->json(['published' => true]));
        self::assertSame(200, $set->getStatusCode());
        self::assertTrue($this->decode($set)['published'] ?? null);

        $response = $this->request('GET', '/api/v1/public/demo');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $page = $this->decode($response);

        // Products: the active ones, each with what is on sale — the draft
        // is not, the retired product is not listed at all.
        $products = $this->listIn($page, 'products');
        self::assertSame(['atlas', 'boreas'], array_column($products, 'code'));
        $offers = $this->listIn($products[0], 'offers');
        self::assertCount(1, $offers);
        self::assertSame('pro-monthly', $offers[0]['code'] ?? null);
        self::assertSame('pro', $offers[0]['plan'] ?? null);
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $offers[0]['price'] ?? null);
        self::assertTrue($offers[0]['publicly_listed'] ?? null);

        // Organisations: root, holdings, subscription, people with roles —
        // one row per person, and nobody who is only waiting.
        $tenants = $this->listIn($page, 'tenants');
        self::assertSame(['acme', 'globex'], array_column($tenants, 'slug'));
        $acme = $tenants[0];
        self::assertTrue($acme['is_default'] ?? null);
        self::assertSame('OPEN', $acme['join_policy'] ?? null);
        self::assertSame(['atlas', 'boreas'], $acme['products'] ?? null);
        $subscriptions = $this->listIn($acme, 'subscriptions');
        self::assertSame('Pro monthly', $subscriptions[0]['offer'] ?? null);
        $members = $this->listIn($acme, 'members');
        self::assertSame(['ada@acme.test', 'grace@acme.test'], array_column($members, 'email'));
        self::assertSame(['TENANT_ADMIN'], $members[0]['roles'] ?? null);
        self::assertSame(['USER'], $members[1]['roles'] ?? null);

        $globex = $tenants[1];
        self::assertFalse($globex['is_default'] ?? null);
        self::assertSame('APPROVAL', $globex['join_policy'] ?? null);
        self::assertSame([], $globex['members'] ?? null);

        // And off again, at once.
        $this->request('PUT', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer ola-token'], $this->json(['published' => false]));
        self::assertSame(404, $this->request('GET', '/api/v1/public/demo')->getStatusCode());
    }

    public function testOnlyThePlatformAdministratorMovesTheSwitch(): void
    {
        $body = $this->json(['published' => true]);

        self::assertSame(403, $this->request('PUT', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer sam-token'], $body)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'], $body)->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/v1/public/demo')->getStatusCode());

        $bad = $this->request('PUT', '/api/v1/staff/demo/page', ['Authorization' => 'Bearer ola-token'], $this->json(['published' => 'yes']));
        self::assertSame(400, $bad->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------

    private function person(string $subject, string $email, string $name): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email, display_name) VALUES (:s, :e, :n) RETURNING id',
            ['s' => $subject, 'e' => $email, 'n' => $name],
        );
    }

    private function member(string $tenantId, string $userId, string $productId, string $role, string $status): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id, status) VALUES (:t, :u, :p, :s)',
            ['t' => $tenantId, 'u' => $userId, 'p' => $productId, 's' => $status],
        );
        $this->connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :t, :u, :p, id FROM roles WHERE code = :r',
            ['t' => $tenantId, 'u' => $userId, 'p' => $productId, 'r' => $role],
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function listIn(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        self::assertIsArray($list);
        $rows = [];
        foreach ($list as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $k => $v) {
                $typed[(string) $k] = $v;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
