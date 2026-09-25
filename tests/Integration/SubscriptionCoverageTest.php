<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
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
 * Who a subscription covers (ADR-053, 2026-09-25).
 *
 * **Buying covers people; membership does not.** Joining an organisation
 * gets somebody a role; it does not get them an entitlement. A subscription
 * covers the person who took it out and those they have added to it, within
 * the number of people their offer sells — and it does so whether the
 * contracting party is the organisation or one person.
 *
 * The operator found the rule's absence by using their own product: they
 * added somebody to a tenant, and that person — on no subscription, holding
 * no seat — received every capability the tenant had bought and could read
 * every project in it. The `users` quota meanwhile bounded a list nobody was
 * on, which is a number a customer pays for that bounds nothing.
 *
 * Against the real database on purpose. Who is on which subscription is a
 * real column and a real join; a double that stored capabilities by tenant
 * could not tell the owner of one from a colleague and would pass whatever
 * the rule was.
 */
#[CoversNothing]
final class SubscriptionCoverageTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $owner = '';
    private string $colleague = '';
    private string $outsider = '';
    private string $offer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->owner = $this->person('sub-ada', 'ada@acme.test');
        $this->colleague = $this->person('sub-bo', 'bo@acme.test');
        $this->outsider = $this->person('sub-cy', 'cy@acme.test');

        $this->seedCatalogue();

        // All three are members of the same organisation, with the same
        // role and the same permissions. That is the point: what separates
        // them is the subscription, and nothing else.
        $membership = fn (string $userId): TenantMembership => new TenantMembership(
            $this->tenant,
            $userId,
            $this->product,
            ['TENANT_ADMIN'],
            ['projects.read', 'projects.write', 'subscription.read', 'subscription.manage'],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'bo-token' => 'sub-bo',
                'cy-token' => 'sub-cy',
            ]),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                $membership($this->owner),
                $membership($this->colleague),
                $membership($this->outsider),
            ]),
        ]);
    }

    public function testAMemberOnNoSubscriptionReachesNothingAndIsToldWhy(): void
    {
        $this->subscribe();

        // The person who bought it works.
        self::assertSame(200, $this->projects('ada-token')->getStatusCode());

        // Their colleague, identical in every way but the subscription, does
        // not — and this is the reproduction of what the operator found.
        $refused = $this->projects('bo-token');

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($refused)['code'] ?? null);
        self::assertSame([], $this->capabilities('bo-token'));
    }

    /**
     * The refusal has to be its own, or it sends people to the wrong place.
     *
     * `ENTITLEMENT_REQUIRED` means the organisation never bought the
     * feature, and is answered by buying it. This means it did, and is
     * answered by whoever owns the subscription giving this person a place.
     * Told the first when the second is true, somebody goes and buys what
     * their colleague is already paying for.
     */
    public function testTheRefusalIsNotTheOneThatMeansBuySomething(): void
    {
        $this->subscribe();

        self::assertNotSame(
            'ENTITLEMENT_REQUIRED',
            $this->errorOf($this->projects('bo-token'))['code'] ?? null,
        );
    }

    public function testTheOwnerAddsSomebodyAndTheyAreCoveredFromThatMoment(): void
    {
        $this->subscribe();

        self::assertSame(403, $this->projects('bo-token')->getStatusCode());

        $added = $this->addPerson($this->colleague);

        self::assertSame(201, $added->getStatusCode());
        self::assertSame(200, $this->projects('bo-token')->getStatusCode());
        // And they hold what the offer grants, not a subset: a place on a
        // subscription is a place on all of it.
        self::assertSame(['advanced_3d', 'max_projects', 'users'], $this->capabilities('bo-token'));
    }

    /**
     * The number the offer sells is what bounds the list, and it counts the
     * owner: this offer sells two, so the owner plus one.
     *
     * Before ADR-053 this quota was decorative for an organisation's
     * subscription — it bounded a list while entitlement came from
     * membership regardless, so exceeding it changed nothing and respecting
     * it changed nothing either.
     */
    public function testTheNumberSoldIsWhatBoundsTheList(): void
    {
        $this->subscribe();

        self::assertSame(201, $this->addPerson($this->colleague)->getStatusCode());

        $third = $this->addPerson($this->outsider);

        self::assertSame(409, $third->getStatusCode());
        self::assertSame('PEOPLE_QUOTA_REACHED', $this->errorOf($third)['code'] ?? null);
        // And the refusal held: the third person reaches nothing.
        self::assertSame(403, $this->projects('cy-token')->getStatusCode());
    }

    /**
     * The tenant-wide question is unchanged, and this is the assertion that
     * would have caught the way this change could have gone wrong silently.
     *
     * Naming nobody asks what the *organisation* bought. That answer is what
     * usage is measured against and what the console shows, and neither is
     * about any one person. Written without the `CASE` in `IN_FORCE`,
     * `IS DISTINCT FROM NULL` is true of every subscription and this answer
     * would have emptied — quotas, staff screens and readiness all reading
     * zero, with nothing failing loudly.
     */
    public function testTheTenantWideAnswerStillSaysWhatTheOrganisationBought(): void
    {
        $this->subscribe();

        $entitlements = $this->container()->get(EntitlementRepository::class);
        self::assertInstanceOf(EntitlementRepository::class, $entitlements);

        $wide = $entitlements->capabilitiesFor($this->tenant, $this->product);
        sort($wide);

        self::assertSame(['advanced_3d', 'max_projects', 'users'], $wide);

        // And coverage is a different question from that one: the colleague
        // is not covered even though the organisation holds all three.
        self::assertFalse($entitlements->covers($this->tenant, $this->product, $this->colleague));
        self::assertTrue($entitlements->covers($this->tenant, $this->product, $this->owner));
    }

    /**
     * An override grants a feature and covers nobody.
     *
     * Staff hand out an exception to an organisation; they do not hand out a
     * place on a subscription. A coverage question answered through
     * entitlements alone would let one support decision cover every member,
     * which is the shape of the hole ADR-053 closes.
     */
    public function testAnOverrideGrantsAFeatureAndCoversNobody(): void
    {
        $feature = $this->id("SELECT id FROM features WHERE code = 'advanced_3d'");

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO entitlements
                    (tenant_id, product_id, feature_id, source, limit_value, valid_from)
                VALUES (:tenant, :product, :feature, 'OVERRIDE', NULL, now() - interval '1 day')
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'feature' => $feature],
        );

        $entitlements = $this->container()->get(EntitlementRepository::class);
        self::assertInstanceOf(EntitlementRepository::class, $entitlements);

        // The whole organisation holds it — that is what an override is for.
        self::assertSame(['advanced_3d'], $entitlements->capabilitiesFor($this->tenant, $this->product, $this->owner));
        // And it covers nobody, so it opens no workspace.
        self::assertFalse($entitlements->covers($this->tenant, $this->product, $this->owner));
        self::assertSame(403, $this->projects('ada-token')->getStatusCode());
    }

    // --- The world -----------------------------------------------------------

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }

    private function person(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
    }

    private function subscribe(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/subscription',
            $this->headers('ada-token'),
            $this->json(['offer_id' => $this->offer]),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    private function addPerson(string $userId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/people',
            $this->headers('ada-token'),
            $this->json(['user_id' => $userId]),
        );
    }

    private function projects(string $token): ResponseInterface
    {
        return $this->request('GET', '/api/v1/projects', $this->headers($token));
    }

    /**
     * @return list<string>
     */
    private function capabilities(string $token): array
    {
        $body = $this->decode($this->request('GET', '/api/v1/me/entitlements', $this->headers($token)));
        $capabilities = $body['capabilities'] ?? [];

        self::assertIsArray($capabilities);

        $codes = array_values(array_filter($capabilities, 'is_string'));
        sort($codes);

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
    }

    private function seedCatalogue(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'project_schema_versions', CAST('{"supported":[1]}' AS jsonb))
                SQL,
            ['product' => $this->product],
        );

        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 10) RETURNING id",
            ['product' => $this->product],
        );

        $feature = 'INSERT INTO features (code, name, kind, unit) VALUES (:code, :code, :kind, :unit) RETURNING id';

        $projects = $this->id($feature, ['code' => 'max_projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $advanced = $this->id($feature, ['code' => 'advanced_3d', 'kind' => 'BOOLEAN', 'unit' => null]);
        // The one this test turns on: how many people the subscription
        // covers, counting the person who bought it.
        $users = $this->id($feature, ['code' => 'users', 'kind' => 'QUOTA', 'unit' => 'users']);

        $this->offer = $this->id(
            "INSERT INTO offers (product_id, plan_id, code, name) VALUES (:product, :plan, 'pro', 'Pro') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $this->offer],
        );

        foreach ([[$projects, 50], [$advanced, null], [$users, 2]] as [$featureId, $limit]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    VALUES (:version, :feature, :limit)
                    SQL,
                ['version' => $version, 'feature' => $featureId, 'limit' => $limit],
            );
        }

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );
    }
}
