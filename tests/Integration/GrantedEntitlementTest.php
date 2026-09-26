<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * An entitlement the platform gives, without a sale (docs/tenant-roots.md
 * §2.8) — through the real pipeline and the real database.
 *
 * The claim is that a grant is an entitlement like any other: the §10.6
 * chain answers `/me/entitlements` and `/me/permissions` from it exactly as
 * from a subscription's rows, and the platform's decision is traced. So the
 * assertions read the tenant's own answer after the staff call, not the
 * staff call's echo.
 */
#[CoversNothing]
final class GrantedEntitlementTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $boreas = '';
    private string $tenant = '';
    private string $ada = '';
    private string $ola = '';
    private string $sam = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $this->ola = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->sam = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        // Acme holds atlas alone; Ada administers it.
        TestDatabase::assignProduct($this->connection, $this->tenant, $this->atlas);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $this->tenant, 'user' => $this->ada, 'product' => $this->atlas],
        );
        $this->connection->executeStatement(
            "INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = 'TENANT_ADMIN'",
            ['tenant' => $this->tenant, 'user' => $this->ada, 'product' => $this->atlas],
        );

        foreach ([[$this->ola, 'PLATFORM_ADMIN'], [$this->sam, 'SUPPORT_ADMIN']] as [$user, $role]) {
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        // The platform's features, and a Pro plan whose active version grants
        // two of them. The features are nobody's product since 2026-09-24;
        // the *grant* is Atlas's, because it lives on an offer version.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO features (code, name, kind, unit) VALUES
                    ('advanced_3d', 'Advanced 3D', 'BOOLEAN', NULL),
                    ('max_projects', 'Projects', 'QUOTA', 'projects'),
                    ('exports', 'Exports', 'QUOTA', 'exports')
                SQL,
        );
        $plan = $this->id("INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 20) RETURNING id", ['p' => $this->atlas]);
        $offer = $this->id("INSERT INTO offers (product_id, plan_id, code, name) VALUES (:p, :plan, 'pro-monthly', 'Pro monthly') RETURNING id", ['p' => $this->atlas, 'plan' => $plan]);
        $version = $this->id(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:o, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day') RETURNING id",
            ['o' => $offer],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                SELECT :v, id, CASE code WHEN 'max_projects' THEN 10 ELSE NULL END
                  FROM features WHERE code IN ('advanced_3d', 'max_projects')
                SQL,
            ['v' => $version],
        );
        // Published after its grants are written: a published version is frozen.
        $this->connection->executeStatement("UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :v", ['v' => $version]);

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada', 'ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->atlas, 'atlas', 'Atlas', true),
                new Product($this->boreas, 'boreas', 'Boreas', true),
            ]),
        ]);
    }

    public function testAGrantIsAnEntitlementTheTenantResolvesLikeAnyOther(): void
    {
        // Nothing bought: nothing to use.
        self::assertSame([], $this->mine()['capabilities'] ?? null);

        $response = $this->grant(['plan' => 'pro', 'features' => [['code' => 'exports', 'limit' => 5]]]);

        self::assertSame(200, $response->getStatusCode());
        $granted = $this->decode($response)['entitlement'] ?? null;
        self::assertIsArray($granted);
        self::assertSame($this->ola, $granted['granted_by'] ?? null);
        self::assertArrayHasKey('valid_until', $granted);
        self::assertNull($granted['valid_until']);

        // The plan's grants and the explicit one, read through the §10.6
        // chain with their source said.
        $mine = $this->mine();
        self::assertSame(['advanced_3d', 'exports', 'max_projects'], $mine['capabilities'] ?? null);
        $sources = [];
        foreach ($this->entitlements() as $entitlement) {
            $feature = $entitlement['feature'] ?? null;
            self::assertIsString($feature);
            $sources[$feature] = [$entitlement['source'] ?? null, $entitlement['limit'] ?? null];
        }
        self::assertSame(['GRANT', 10], $sources['max_projects'] ?? null);
        self::assertSame(['GRANT', 5], $sources['exports'] ?? null);

        // Traced with what was given — a commercial decision somebody can
        // read back.
        $detail = $this->connection->fetchOne(
            "SELECT detail FROM staff_access_log WHERE action = 'GRANT_ENTITLEMENT' AND tenant_id = :tenant",
            ['tenant' => $this->tenant],
        );
        self::assertIsString($detail);
        self::assertStringContainsString('"exports": 5', $detail);
        self::assertStringContainsString('"plan": "pro"', $detail);
    }

    public function testExplicitFeaturesWinOverThePlansAndAGrantReplacesTheLast(): void
    {
        // The Pro plan, but with more projects.
        $this->grant(['plan' => 'pro', 'features' => [['code' => 'max_projects', 'limit' => 50]]]);
        self::assertSame(50, $this->limitOf('max_projects'));

        // Then a narrower grant: what was there goes, this stays.
        $this->grant(['features' => [['code' => 'advanced_3d']]]);
        self::assertSame(['advanced_3d'], $this->mine()['capabilities'] ?? null);
        self::assertSame(1, $this->grantRows());
    }

    public function testAGrantLapsesOnTheClockAndIsWithdrawnByAPerson(): void
    {
        $until = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
        $shown = $this->decode($this->grant(['features' => [['code' => 'advanced_3d']], 'valid_until' => $until]));
        self::assertIsArray($shown['entitlement'] ?? null);
        self::assertIsString($shown['entitlement']['valid_until'] ?? null);
        self::assertSame(['advanced_3d'], $this->mine()['capabilities'] ?? null);

        // Read back from the console, and the same thing.
        $read = $this->request('GET', $this->path(), ['Authorization' => 'Bearer ola-token']);
        self::assertSame(200, $read->getStatusCode());
        $entitlement = $this->decode($read)['entitlement'] ?? null;
        self::assertIsArray($entitlement);
        $features = $entitlement['features'] ?? null;
        self::assertIsArray($features);
        self::assertSame(['advanced_3d'], array_column(array_filter($features, 'is_array'), 'code'));

        // Yesterday: refused as a grant, not silently written as lapsed.
        $past = $this->grant(['features' => [['code' => 'advanced_3d']], 'valid_until' => '2020-01-01T00:00:00Z']);
        self::assertSame(400, $past->getStatusCode());

        // Moved into the past by hand: the resolver stops granting, nothing
        // sweeps and nothing invoices.
        $this->connection->executeStatement("UPDATE entitlements SET valid_from = now() - interval '2 minutes', valid_until = now() - interval '1 minute' WHERE source = 'GRANT'");
        self::assertSame([], $this->mine()['capabilities'] ?? null);

        $withdrawn = $this->request('DELETE', $this->path(), ['Authorization' => 'Bearer ola-token']);
        self::assertSame(204, $withdrawn->getStatusCode());
        self::assertSame(0, $this->grantRows());
        $none = $this->request('GET', $this->path(), ['Authorization' => 'Bearer ola-token']);
        self::assertArrayHasKey('entitlement', $this->decode($none));
        self::assertNull($this->decode($none)['entitlement']);
    }

    public function testAGrantNeedsAHeldProductAKnownFeatureAndSomething(): void
    {
        // Boreas is not Acme's: seeing must come before doing (ADR-047).
        $notHeld = $this->grant(['features' => [['code' => 'advanced_3d']]], $this->boreas);
        self::assertSame(404, $notHeld->getStatusCode());
        self::assertSame('TENANT_OR_PRODUCT_NOT_FOUND', $this->errorOf($notHeld)['code'] ?? null);

        $unknown = $this->grant(['features' => [['code' => 'teleport']]]);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('FEATURE_NOT_FOUND', $this->errorOf($unknown)['code'] ?? null);

        $noPlan = $this->grant(['plan' => 'enterprise']);
        self::assertSame(404, $noPlan->getStatusCode());
        self::assertSame('PLAN_NOT_FOUND', $this->errorOf($noPlan)['code'] ?? null);

        $empty = $this->grant(['plan' => null]);
        self::assertSame(409, $empty->getStatusCode());
        self::assertSame('GRANT_EMPTY', $this->errorOf($empty)['code'] ?? null);

        self::assertSame(0, $this->grantRows());
    }

    public function testOnlyThePlatformAdministratorGrants(): void
    {
        // Support reads the tenant and may not give away what the platform
        // sells; a tenant administrator is not staff at all.
        self::assertSame(403, $this->grant(['features' => [['code' => 'advanced_3d']]], null, 'sam-token')->getStatusCode());
        self::assertSame(403, $this->grant(['features' => [['code' => 'advanced_3d']]], null, 'ada-token')->getStatusCode());
        self::assertSame(200, $this->request('GET', $this->path(), ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(0, $this->grantRows());
    }

    // --- A trial, which is not a support exception (2026-09-25) ----------------

    /**
     * **A grant lights the features and opens no workspace, unless it says
     * so.**
     *
     * ADR-053 made coverage a question about subscriptions on a principle
     * that still holds — staff hand out a feature, never a seat — and the
     * consequence went unnoticed: the platform could not give a trial at
     * all. A granted product read "provided by the platform" on the
     * subscription screen and refused every workspace.
     *
     * So the two are now different requests, and this is both of them. The
     * default is the narrow one, because the wide one should take a
     * decision.
     */
    public function testAGrantOpensNoWorkspaceUnlessItSaysItCoversPeople(): void
    {
        $this->grant(['plan' => 'pro', 'features' => [['code' => 'exports', 'limit' => 5]]]);

        // Everything Pro grants, and no way in: the features are live and
        // the workspace refuses, which is exactly right for restoring
        // something a customer is missing.
        self::assertSame(['advanced_3d', 'exports', 'max_projects'], $this->mine()['capabilities'] ?? null);

        $refused = $this->projects();
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($refused)['code'] ?? null);
    }

    public function testAGrantThatCoversPeopleIsATrialAndEveryMemberReachesIt(): void
    {
        $response = $this->grant([
            'plan' => 'pro',
            'covers_people' => true,
            'features' => [['code' => 'exports', 'limit' => 5]],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $granted = $this->decode($response)['entitlement'] ?? null;
        self::assertIsArray($granted);
        self::assertTrue($granted['covers_people'] ?? null);

        // Ada bought nothing and holds no seat. The platform said this
        // organisation may use the product, and it names no people because a
        // trial has none to name.
        self::assertSame(200, $this->projects()->getStatusCode());
    }

    /**
     * "Every member" is every *member* (2026-09-26).
     *
     * A trial names no people, so what stands in for the list is membership
     * of the tenant — and that is checked where the question is answered,
     * not left to the caller. For a day it was not: the branch read only
     * (tenant, product), so `covers()` said yes about anybody at all while a
     * trial was live.
     *
     * Nothing exploited it, because the only caller is the request context
     * and it has resolved a membership before it asks. Asked directly, as
     * here, the old code answered yes about Ola — who is platform staff and
     * no member of Acme. A method whose name is a question about a person
     * must not answer a different question.
     */
    public function testATrialCoversTheOrganisationsMembersAndNobodyElse(): void
    {
        $this->grant([
            'plan' => 'pro',
            'covers_people' => true,
            'features' => [['code' => 'exports', 'limit' => 5]],
        ]);

        $entitlements = $this->container()->get(EntitlementRepository::class);
        self::assertInstanceOf(EntitlementRepository::class, $entitlements);

        self::assertTrue($entitlements->covers($this->tenant, $this->atlas, $this->ada));
        self::assertFalse($entitlements->covers($this->tenant, $this->atlas, $this->ola));
    }

    /**
     * A trial ends by its own clock, like the entitlement it is. Nothing
     * invoices it and nothing renews it — the workspace simply closes again.
     */
    public function testALapsedTrialStopsCoveringAnybody(): void
    {
        $this->grant([
            'plan' => 'pro',
            'covers_people' => true,
            'features' => [['code' => 'exports', 'limit' => 5]],
            'valid_until' => '2026-09-24T23:59:59Z',
        ]);

        $refused = $this->projects();

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($refused)['code'] ?? null);
    }

    /**
     * Withdrawing takes the trial with it: the rows are the grant, and the
     * flag lives on them.
     */
    public function testWithdrawingATrialClosesTheWorkspace(): void
    {
        $this->grant([
            'plan' => 'pro',
            'covers_people' => true,
            'features' => [['code' => 'exports', 'limit' => 5]],
        ]);

        self::assertSame(200, $this->projects()->getStatusCode());

        $this->request('DELETE', $this->path(), ['Authorization' => 'Bearer ola-token']);

        self::assertSame(403, $this->projects()->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------

    private function projects(): ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/v1/projects',
            ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'],
        );
    }

    /** @param array<string, mixed> $body */
    private function grant(array $body, ?string $productId = null, string $token = 'ola-token'): ResponseInterface
    {
        return $this->request('PUT', $this->path($productId), ['Authorization' => 'Bearer ' . $token], $this->json($body));
    }

    private function path(?string $productId = null): string
    {
        return '/api/v1/staff/tenants/' . $this->tenant . '/products/' . ($productId ?? $this->atlas) . '/entitlement';
    }

    /** @return array<string, mixed> */
    private function mine(): array
    {
        return $this->decode($this->request('GET', '/api/v1/me/entitlements', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas']));
    }

    /** @return list<array<string, mixed>> */
    private function entitlements(): array
    {
        return $this->listIn(
            $this->decode($this->request('GET', '/api/v1/entitlements', ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'])),
            'entitlements',
        );
    }

    private function limitOf(string $code): ?int
    {
        foreach ($this->entitlements() as $entitlement) {
            if (($entitlement['feature'] ?? null) === $code) {
                $limit = $entitlement['limit'] ?? null;

                return is_int($limit) ? $limit : null;
            }
        }

        self::fail('No entitlement ' . $code);
    }

    private function grantRows(): int
    {
        $count = $this->connection->fetchOne("SELECT count(*) FROM entitlements WHERE source = 'GRANT'");
        self::assertIsNumeric($count);

        return (int) $count;
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
