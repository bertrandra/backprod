<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Infrastructure\InMemoryCatalogueRepository;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserDirectory;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\InMemoryUserDirectory;
use App\User\Infrastructure\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The catalogue endpoints through the real pipeline.
 *
 * The claims under test are commercial rather than technical: the catalogue
 * belongs to a product, an offer outside its window is not for sale, and a
 * price crosses the wire as an integer.
 */
#[CoversNothing]
final class CatalogueEndpointsTest extends ApiTestCase
{
    private const ALICE = 'user-alice';
    private const MALLORY = 'user-mallory';

    private const ATLAS = 'prod-atlas';
    private const BEACON = 'prod-beacon';

    protected function setUp(): void
    {
        parent::setUp();

        $this->override([
            UserDirectory::class => new InMemoryUserDirectory(),

            UserRepository::class => new InMemoryUserRepository([
                new PlatformUser(self::ALICE, self::ALICE, 'alice@example.test'),
                new PlatformUser(self::MALLORY, self::MALLORY, 'mallory@example.test'),
            ]),

            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => self::ALICE,
                'mallory-token' => self::MALLORY,
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product(self::ATLAS, 'atlas', 'Atlas', true),
                new Product(self::BEACON, 'beacon', 'Beacon', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership('tenant-acme', self::ALICE, self::ATLAS, ['USER'], ['catalog.read']),
                // Mallory is a member, but of a role that cannot read the
                // catalogue — the permission is what is being tested.
                new TenantMembership('tenant-acme', self::MALLORY, self::ATLAS, ['USER'], []),
            ]),

            CatalogueRepository::class => self::catalogue(),

            EntitlementRepository::class => new InMemoryEntitlementRepository([]),
        ]);
    }

    public function testPlansAreListedInTierOrder(): void
    {
        $response = $this->request('GET', '/api/v1/plans', $this->aliceHeaders());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'plans' => [
                ['id' => 'plan-free', 'code' => 'FREE', 'name' => 'Free', 'rank' => 10],
                ['id' => 'plan-pro', 'code' => 'PRO', 'name' => 'Pro', 'rank' => 20],
            ],
        ], $this->decode($response));
    }

    /**
     * A rank rather than an ordering the code knows: comparing two numbers is
     * how an offer change is classified as an upgrade without any code
     * learning that PRO outranks FREE (§13).
     */
    public function testPlansCarryARankSoTiersCanBeComparedWithoutNamingThem(): void
    {
        $plans = $this->decode($this->request('GET', '/api/v1/plans', $this->aliceHeaders()))['plans'] ?? null;

        self::assertIsArray($plans);

        $cheaper = $plans[0] ?? null;
        $dearer = $plans[1] ?? null;
        self::assertIsArray($cheaper);
        self::assertIsArray($dearer);

        self::assertLessThan($dearer['rank'] ?? 0, $cheaper['rank'] ?? 0);
    }

    public function testFeaturesReportWhetherTheyAreCountedOrHeld(): void
    {
        $response = $this->request('GET', '/api/v1/features', $this->aliceHeaders());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'features' => [
                [
                    'id' => 'feature-3d',
                    'code' => 'advanced_3d',
                    'name' => 'Advanced 3D',
                    // A sentence the operator may write beside the name
                    // (2026-09-24), translated like it; null until somebody
                    // does.
                    'description' => null,
                    'kind' => 'BOOLEAN',
                    'unit' => null,
                ],
                [
                    'id' => 'feature-projects',
                    'code' => 'max_projects',
                    'name' => 'Projects',
                    'description' => null,
                    'kind' => 'QUOTA',
                    'unit' => 'projects',
                ],
            ],
        ], $this->decode($response));
    }

    /**
     * A price is an integer count of minor units with a currency beside it.
     * Anything that arrives as 19.0 has already lost the ability to be
     * summed without error.
     */
    public function testAPriceIsIntegerMinorUnitsAndACurrency(): void
    {
        $offers = $this->decode($this->request('GET', '/api/v1/offers', $this->aliceHeaders()))['offers'] ?? null;

        self::assertIsArray($offers);
        self::assertCount(2, $offers);

        $pro = $offers[1] ?? null;
        self::assertIsArray($pro);

        $version = $pro['version'] ?? null;
        self::assertIsArray($version);

        $price = $version['price'] ?? null;
        self::assertIsArray($price);

        // assertSame is strict, so this asserts the type as much as the
        // value: 2900.0 would not match 2900. A separate assertIsInt after
        // it asserts nothing that this line has not already established.
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $price);
    }

    /**
     * The two meanings of a null limit, kept apart. A boolean capability has
     * nothing to count; an unlimited quota has no ceiling. Both would be
     * `null` alone, so the flag says which.
     */
    public function testABooleanGrantAndAnUnlimitedQuotaAreDistinguishable(): void
    {
        $offers = $this->decode($this->request('GET', '/api/v1/offers', $this->aliceHeaders()))['offers'] ?? null;

        self::assertIsArray($offers);

        $pro = $offers[1] ?? null;
        self::assertIsArray($pro);

        $version = $pro['version'] ?? null;
        self::assertIsArray($version);

        $grants = $version['grants'] ?? null;
        self::assertIsArray($grants);

        self::assertSame([
            [
                'feature' => 'advanced_3d',
                'name' => 'Advanced 3D',
                'kind' => 'BOOLEAN',
                'unit' => null,
                'limit' => null,
                'unlimited' => false,
            ],
            [
                'feature' => 'max_projects',
                'name' => 'Projects',
                'kind' => 'QUOTA',
                'unit' => 'projects',
                'limit' => null,
                'unlimited' => true,
            ],
        ], $grants);
    }

    /**
     * §12 keeps expired offers for history, not for display. The withdrawn
     * offer in this catalogue is never listed.
     */
    public function testAnOfferOutsideItsWindowIsNotOnSale(): void
    {
        $offers = $this->decode($this->request('GET', '/api/v1/offers', $this->aliceHeaders()))['offers'] ?? null;

        self::assertIsArray($offers);
        self::assertSame(
            ['free', 'pro-monthly'],
            array_map(
                static fn (mixed $o): mixed => is_array($o) ? ($o['code'] ?? null) : null,
                $offers,
            ),
        );
    }

    /**
     * And by id it answers exactly as an offer that never existed. What a
     * company has stopped selling is commercial information.
     */
    public function testAWithdrawnOfferIsReportedAsUnknown(): void
    {
        $withdrawn = $this->request('GET', '/api/v1/offers/offer-legacy', $this->aliceHeaders());
        $fictional = $this->request('GET', '/api/v1/offers/offer-never-existed', $this->aliceHeaders());

        self::assertSame(404, $withdrawn->getStatusCode());
        self::assertSame('OFFER_NOT_FOUND', $this->errorOf($withdrawn)['code'] ?? null);
        self::assertSame($fictional->getStatusCode(), $withdrawn->getStatusCode());
        self::assertSame(
            $this->errorOf($fictional)['code'] ?? null,
            $this->errorOf($withdrawn)['code'] ?? null,
        );
    }

    public function testAnOfferOnSaleIsReadableById(): void
    {
        $response = $this->request('GET', '/api/v1/offers/offer-pro', $this->aliceHeaders());

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('pro-monthly', $body['code'] ?? null);
        self::assertSame(['id' => 'plan-pro', 'code' => 'PRO', 'name' => 'Pro', 'rank' => 20], $body['plan'] ?? null);
    }

    /**
     * The catalogue belongs to a product. Beacon sells nothing here, and
     * Atlas's offers must not appear under it.
     */
    public function testTheCatalogueIsScopedToTheResolvedProduct(): void
    {
        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership('tenant-acme', self::ALICE, self::BEACON, ['USER'], ['catalog.read']),
            ]),
        ]);

        $response = $this->request('GET', '/api/v1/offers', [
            'Authorization' => 'Bearer alice-token',
            'X-Product' => 'beacon',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['offers' => []], $this->decode($response));
    }

    public function testReadingTheCatalogueNeedsThePermission(): void
    {
        $response = $this->request('GET', '/api/v1/plans', [
            'Authorization' => 'Bearer mallory-token',
            'X-Product' => 'atlas',
        ]);

        self::assertSame(403, $response->getStatusCode());

        $error = $this->errorOf($response);
        self::assertSame('PERMISSION_DENIED', $error['code'] ?? null);

        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('catalog.read', $details['permission'] ?? null);
    }

    public function testTheCatalogueIsNotPublic(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/offers')->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function aliceHeaders(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
    }

    /**
     * Atlas sells two offers and has withdrawn a third.
     */
    private static function catalogue(): InMemoryCatalogueRepository
    {
        $free = new Plan('plan-free', 'FREE', 'Free', 10);
        $pro = new Plan('plan-pro', 'PRO', 'Pro', 20);

        $advanced3d = new Feature('feature-3d', 'advanced_3d', 'Advanced 3D', Feature::BOOLEAN, null);
        $maxProjects = new Feature('feature-projects', 'max_projects', 'Projects', Feature::QUOTA, 'projects');

        $opened = new DateTimeImmutable('-1 year');
        $closed = new DateTimeImmutable('-1 month');

        return new InMemoryCatalogueRepository(
            plans: [self::ATLAS => [$free, $pro]],
            // The platform's one list, no longer keyed by product (2026-09-24).
            features: [$advanced3d, $maxProjects],
            offers: [
                self::ATLAS => [
                    new OfferCandidate('offer-free', 'free', 'Free', $free, [
                        new OfferVersion(
                            'version-free-1',
                            1,
                            OfferVersion::ACTIVE,
                            'MONTHLY',
                            0,
                            'EUR',
                            $opened,
                            null,
                            [new OfferGrant($maxProjects, 3)],
                        ),
                    ]),
                    new OfferCandidate('offer-pro', 'pro-monthly', 'Pro, monthly', $pro, [
                        new OfferVersion(
                            'version-pro-2',
                            2,
                            OfferVersion::ACTIVE,
                            'MONTHLY',
                            2900,
                            'EUR',
                            $opened,
                            null,
                            [new OfferGrant($advanced3d, null), new OfferGrant($maxProjects, null)],
                        ),
                    ]),
                    // Still ACTIVE, but its window closed a month ago: expiry
                    // is a fact about the clock, not about a job having run.
                    new OfferCandidate('offer-legacy', 'legacy', 'Legacy', $pro, [
                        new OfferVersion(
                            'version-legacy-1',
                            1,
                            OfferVersion::ACTIVE,
                            'YEARLY',
                            9900,
                            'EUR',
                            $opened,
                            $closed,
                            [],
                        ),
                    ]),
                ],
            ],
        );
    }
}
