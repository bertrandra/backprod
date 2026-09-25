<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * `GET /organisation/subscriptions` — who holds what, and how full each one
 * is (2026-09-25).
 *
 * The screen behind it exists because the tenant surface sells seats only
 * (ADR-055): an organisation's subscriptions belong to its people one by one,
 * and nothing showed them together. An administrator does not buy — they
 * administer — and they cannot administer what they cannot see.
 *
 * Driven through HTTP against the real database, because the answer is a
 * join: the holder comes from `users`, the places sold from the offer
 * version's grant, the places used from `subscription_members`. A double
 * would prove the presenter and nothing else.
 */
#[CoversNothing]
final class OrganisationSubscriptionsTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $admin = '';
    private string $holder = '';
    private string $colleague = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->admin = $this->person('sub-ada', 'ada@acme.test', 'Ada');
        $this->holder = $this->person('sub-bo', 'bo@acme.test', 'Bo');
        $this->colleague = $this->person('sub-cam', 'cam@acme.test', 'Cam');
        // Dee is on no subscription, and that is the point: somebody the
        // organisation has but nobody bought for contributes no row.
        $this->person('sub-dee', 'dee@acme.test', 'Dee');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'bo-token' => 'sub-bo',
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $this->admin, $this->product, ['TENANT_ADMIN'], [
                    'tenant.manage', 'subscription.read', 'subscription.manage',
                ]),
                // Bo holds `subscription.manage` deliberately. A USER really
                // does — it is what lets a seat holder put people on their own
                // seat — so a gate on it would hand them the whole register,
                // which is the mistake this fixture exists to catch.
                new TenantMembership($this->tenant, $this->holder, $this->product, ['USER'], [
                    'subscription.read', 'subscription.manage',
                ]),
            ]),
        ]);
    }

    public function testItNamesWhoHoldsWhatAndHowManyPlacesAreTaken(): void
    {
        // Pro sells three people counting the holder; Bo has added Cam, so
        // one place is free and Dee is on nothing.
        $pro = $this->offer('pro', 'Pro', 2900, usersLimit: 3);
        $subscription = $this->subscribe($pro, $this->holder);
        $this->connection->executeStatement(
            'INSERT INTO subscription_members (subscription_id, user_id) VALUES (:s, :u)',
            ['s' => $subscription, 'u' => $this->colleague],
        );

        $rows = $this->read();

        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame($subscription, $row['id'] ?? null);
        self::assertSame('USER', $row['subscriber_kind'] ?? null);
        self::assertTrue($row['live'] ?? null);
        self::assertSame('Pro', $row['offer_name'] ?? null);
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $row['price'] ?? null);

        $holder = $row['holder'] ?? null;
        self::assertIsArray($holder);
        self::assertSame($this->holder, $holder['user_id'] ?? null);
        self::assertSame('Bo', $holder['name'] ?? null);
        self::assertSame('bo@acme.test', $holder['email'] ?? null);

        // The whole point of the screen: two of the three places are taken,
        // and the holder is one of them.
        self::assertSame(3, $row['places_sold'] ?? null);
        self::assertSame(2, $row['places_used'] ?? null);
    }

    /**
     * An offer that grants no `users` feature covers its holder alone, and one
     * that grants it with no limit covers everybody. Both are a `NULL` limit
     * in the database, and telling them apart is the only reason the read
     * model asks whether the grant exists at all.
     */
    public function testAnOfferThatSellsNoPlacesCoversItsHolderAndAnUnlimitedOneSellsNull(): void
    {
        $this->subscribe($this->offer('solo', 'Solo', 500, usersLimit: null, grantsUsers: false), $this->holder);
        $this->subscribe($this->offer('scale', 'Scale', 9900, usersLimit: null, grantsUsers: true), $this->colleague);

        $sold = [];
        $used = [];

        foreach ($this->read() as $row) {
            $name = $row['offer_name'];
            self::assertIsString($name);

            $sold[$name] = $row['places_sold'];
            $used[$name] = $row['places_used'];
        }

        // `??` is no use here: unlimited *is* null, and `$sold['Scale'] ?? x`
        // cannot tell it from a missing row. Asked by key.
        self::assertArrayHasKey('Solo', $sold);
        self::assertArrayHasKey('Scale', $sold);
        self::assertSame(1, $sold['Solo'], 'no grant covers the holder alone');
        self::assertNull($sold['Scale'], 'a grant with no limit is unlimited');
        // And both count their holder, who is one of the people covered.
        self::assertSame(1, $used['Solo'] ?? null);
        self::assertSame(1, $used['Scale'] ?? null);
    }

    public function testACancelledSubscriptionIsListedAndNotCalledLive(): void
    {
        $subscription = $this->subscribe($this->offer('pro', 'Pro', 2900, usersLimit: 3), $this->holder);
        $this->connection->executeStatement(
            // `ended_at` too, and a period that still runs forwards: the
            // schema refuses a subscription that stopped without saying when,
            // and one whose period ends before it started.
            "UPDATE subscriptions SET status = 'CANCELLED', ended_at = now(),"
            . " current_period_start = now() - interval '31 days',"
            . " current_period_end = now() - interval '1 day' WHERE id = :id",
            ['id' => $subscription],
        );

        $rows = $this->read();

        // Listed, because since ADR-055 this is the only place a past seat can
        // be seen at all — the organisation's own history is empty for good.
        self::assertCount(1, $rows);
        self::assertSame('CANCELLED', $rows[0]['status'] ?? null);
        self::assertFalse($rows[0]['live'] ?? null);
    }

    public function testAMemberMayNotReadWhatTheirColleaguesBought(): void
    {
        $this->subscribe($this->offer('pro', 'Pro', 2900, usersLimit: 3), $this->holder);

        // Bo holds `subscription.read` *and* `subscription.manage` — every
        // member does, the second because a seat holder manages the people on
        // their own seat. Neither is the register of what everybody bought,
        // which answers to `tenant.manage`.
        $response = $this->request('GET', '/api/v1/organisation/subscriptions', $this->headersFor('bo-token'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnOrganisationThatHoldsNothingGetsAnEmptyListNotAnError(): void
    {
        self::assertSame([], $this->read());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $response = $this->request('GET', '/api/v1/organisation/subscriptions', $this->headersFor('ada-token'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $rows = $this->decode($response)['subscriptions'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /** Returns the subscription id. */
    private function subscribe(string $offerVersionId, string $owner): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, status, started_at,
                     current_period_start, current_period_end, subscriber_kind, owner_user_id, subscriber_user_id)
                VALUES (:tenant, :product, :version, 'ACTIVE', now(), now(), now() + interval '30 days',
                        'USER', :owner, :owner)
                RETURNING id
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'version' => $offerVersionId, 'owner' => $owner],
        );
    }

    /** Returns the offer version id. */
    private function offer(string $code, string $name, int $price, ?int $usersLimit, bool $grantsUsers = true): string
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank) VALUES (:p, :c, :n, 10) RETURNING id',
            ['p' => $this->product, 'c' => $code, 'n' => $name],
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name) VALUES (:p, :pl, :c, :n) RETURNING id',
            ['p' => $this->product, 'pl' => $plan, 'c' => $code . '-monthly', 'n' => $name],
        );

        // Born DRAFT, granted, then published — in that order, because a
        // published version is frozen (ADR-033) and the database says so with
        // a trigger rather than trusting a service to.
        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:o, 1, 'DRAFT', 'MONTHLY', :price, 'EUR', now())
                RETURNING id
                SQL,
            ['o' => $offer, 'price' => $price],
        );

        if ($grantsUsers) {
            // One list of features for the whole platform (ADR-052), so this
            // is created once and found again.
            $feature = $this->id(
                <<<'SQL'
                    INSERT INTO features (code, name, kind, unit)
                    VALUES ('users', 'Users', 'QUOTA', 'users')
                    ON CONFLICT (code) DO UPDATE SET name = excluded.name
                    RETURNING id
                    SQL,
            );

            $this->connection->executeStatement(
                'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value) VALUES (:v, :f, :l)',
                ['v' => $version, 'f' => $feature, 'l' => $usersLimit],
            );
        }

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        return $version;
    }

    private function person(string $subject, string $email, string $name): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email, display_name) VALUES (:s, :e, :n) RETURNING id',
            ['s' => $subject, 'e' => $email, 'n' => $name],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Product' => 'atlas',
            'X-Tenant' => $this->tenant,
        ];
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
