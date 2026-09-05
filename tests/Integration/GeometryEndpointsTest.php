<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Geometry\Controller\GeoRoute;
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
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §19 phase 1, through the real pipeline and the real spatial backend.
 *
 * The provider is not doubled. A double would answer whatever this test told
 * it to, and every claim worth making here — that a concave parcel is not
 * reported as overlapping a block sitting in its notch, that a bowtie is
 * refused rather than measured as nothing — is a claim about what core
 * PostgreSQL actually does. So this runs against the database, and the
 * numbers below were checked by hand before they were asserted.
 *
 * §37.4 order: who may ask, then what is refused, then what is computed.
 */
#[CoversNothing]
final class GeometryEndpointsTest extends DatabaseApiTestCase
{
    private const ATLAS = 'prod-atlas';

    /** Acme's plan includes GIS. Globex's does not — that is the only difference. */
    private const ACME = 'tenant-acme';
    private const GLOBEX = 'tenant-globex';

    private const MIA = 'user-mia';
    private const NOAH = 'user-noah';

    /** A 4x3 rectangle: area 12, perimeter 14. */
    private const RECTANGLE = [[[0, 0], [4, 0], [4, 3], [0, 3], [0, 0]]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->override([
            UserDirectory::class => new InMemoryUserDirectory(),

            UserRepository::class => new InMemoryUserRepository([
                new PlatformUser(self::MIA, self::MIA, 'mia@acme.test'),
                new PlatformUser(self::NOAH, self::NOAH, 'noah@globex.test'),
            ]),

            AuthProvider::class => new FakeAuthProvider([
                'mia-token' => self::MIA,
                'noah-token' => self::NOAH,
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product(self::ATLAS, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                // Identical roles and identical (empty) permissions. What
                // separates them is the plan, which is the whole point of a
                // capability: geometry touches no tenant row, so there is no
                // role question to ask, only a bought-or-not question.
                new TenantMembership(self::ACME, self::MIA, self::ATLAS, ['TENANT_ADMIN'], []),
                new TenantMembership(self::GLOBEX, self::NOAH, self::ATLAS, ['TENANT_ADMIN'], []),
            ]),

            EntitlementRepository::class => InMemoryEntitlementRepository::granting([
                self::ACME . ':' . self::ATLAS => [GeoRoute::CAPABILITY],
            ]),
        ]);
    }

    // --- Who may ask --------------------------------------------------------

    public function testATenantWhosePlanLacksGisIsRefused(): void
    {
        $response = $this->measure(self::RECTANGLE, 'noah-token');

        // 403 and not 404: Noah is a member in good standing of a tenant on
        // this product, and what Globex lacks is the plan feature. Hiding
        // that behind a 404 would send him to support instead of to the
        // offer page.
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('ENTITLEMENT_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/geometry/measure',
            ['X-Product' => 'atlas'],
            $this->json(['crs' => 'PROJECTED', 'geometry' => self::geoJson(self::RECTANGLE)]),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // --- What is refused ----------------------------------------------------

    public function testASelfIntersectingRingIsRefusedRatherThanMeasuredAsNothing(): void
    {
        // The bowtie. PostgreSQL measures this as zero — the shoelace sum
        // cancels its two lobes — so a surveyor who closed a plot the wrong
        // way round would be told it has no surface. This is the single most
        // important refusal in the module.
        $response = $this->measure([[[0, 0], [4, 4], [4, 0], [0, 4], [0, 0]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('GEOMETRY_NOT_SIMPLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testARingOfTwoPositionsIsRefusedRatherThanMeasuredAsALine(): void
    {
        // `polygon '((0,0),(1,1))'` parses in PostgreSQL and has area zero.
        $response = $this->measure([[[0, 0], [1, 1], [0, 0]]]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAPolygonWithAHoleIsRefusedRatherThanFlattened(): void
    {
        // Core PostgreSQL cannot represent a hole. Keeping the outer ring
        // would answer a different question from the one that was asked.
        $response = $this->measure([
            [[0, 0], [9, 0], [9, 9], [0, 9], [0, 0]],
            [[1, 1], [2, 1], [2, 2], [1, 2], [1, 1]],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('one linear ring', $this->requirementOf($response));
    }

    public function testAThirdOrdinateIsRefusedRatherThanDropped(): void
    {
        $response = $this->measure([[[0, 0, 5], [4, 0, 5], [4, 3, 5], [0, 3, 5], [0, 0, 5]]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('two-dimensional', $this->requirementOf($response));
    }

    public function testMeasuringGeographicCoordinatesIsRefused(): void
    {
        // Roughly a city block in Lille, in degrees. Its "area" in degrees
        // squared is 2.5e-7, which is not an area of anything.
        $response = $this->measure(
            [[[3.06, 50.63], [3.061, 50.63], [3.061, 50.6305], [3.06, 50.6305], [3.06, 50.63]]],
            'mia-token',
            'GEOGRAPHIC',
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('PROJECTION_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnUnknownCoordinateReferenceIsRefused(): void
    {
        $response = $this->measure(self::RECTANGLE, 'mia-token', 'WGS84');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRepeatedCandidateIdsAreRefused(): void
    {
        $response = $this->intersections(self::geoJson(self::RECTANGLE), [
            ['id' => 'parcel-a', 'geometry' => self::geoJson(self::RECTANGLE)],
            ['id' => 'parcel-a', 'geometry' => self::geoJson(self::RECTANGLE)],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('distinct', $this->requirementOf($response));
    }

    // --- What is computed ---------------------------------------------------

    public function testARectangleIsMeasured(): void
    {
        $response = $this->measure(self::RECTANGLE);

        self::assertSame(200, $response->getStatusCode());

        $measurement = $this->measurementOf($response);
        self::assertSame(4, $measurement['vertices'] ?? null);
        self::assertEqualsWithDelta(12.0, self::number($measurement['area'] ?? null), 1e-9);
        self::assertEqualsWithDelta(14.0, self::number($measurement['perimeter'] ?? null), 1e-9);
        $box = $measurement['bounding_box'] ?? null;
        self::assertIsArray($box);

        foreach (['min_x' => 0.0, 'min_y' => 0.0, 'max_x' => 4.0, 'max_y' => 3.0] as $corner => $expected) {
            self::assertEqualsWithDelta($expected, self::number($box[$corner] ?? null), 1e-9);
        }
    }

    public function testAConcaveParcelIsMeasuredByItsRealOutlineAndNotItsBox(): void
    {
        // An L: 4x1 along the bottom plus 1x2 up the left, so 6 — half what
        // its 4x3 bounding box would give.
        $response = $this->measure([[[0, 0], [4, 0], [4, 1], [1, 1], [1, 3], [0, 3], [0, 0]]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsWithDelta(6.0, self::number($this->measurementOf($response)['area'] ?? null), 1e-9);
    }

    public function testTheOverlapTestIsGeometricRatherThanByBoundingBox(): void
    {
        // The block sits in the L's notch: their bounding boxes overlap and
        // the shapes do not touch. A backend that compared boxes would report
        // this parcel as clipped, and a planning check would refuse a
        // building that is nowhere near the boundary.
        $response = $this->intersections(
            self::geoJson([[[0, 0], [4, 0], [4, 1], [1, 1], [1, 4], [0, 4], [0, 0]]]),
            [['id' => 'notch', 'geometry' => self::geoJson([[[2, 2], [3, 2], [3, 3], [2, 3], [2, 2]]])]],
        );

        self::assertSame(200, $response->getStatusCode());

        $relation = $this->relationsOf($response)[0] ?? [];
        self::assertFalse($relation['intersects'] ?? null);
        self::assertFalse($relation['contains'] ?? null);
        self::assertEqualsWithDelta(1.0, self::number($relation['distance'] ?? null), 1e-9);
    }

    public function testContainmentAndOverlapAreDistinguishedAndOrderIsPreserved(): void
    {
        $response = $this->intersections(
            self::geoJson([[[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]]]),
            [
                ['id' => 'far', 'geometry' => self::geoJson([[[9, 9], [10, 9], [10, 10], [9, 10], [9, 9]]])],
                ['id' => 'clipped', 'geometry' => self::geoJson([[[2, 2], [6, 2], [6, 6], [2, 6], [2, 2]]])],
                ['id' => 'swallowed', 'geometry' => self::geoJson([[[1, 1], [2, 1], [2, 2], [1, 2], [1, 1]]])],
            ],
        );

        self::assertSame(200, $response->getStatusCode());

        $relations = $this->relationsOf($response);

        // The answer comes back in the order the candidates were sent, not
        // whatever order the set happened to come out of the database in.
        self::assertSame(['far', 'clipped', 'swallowed'], array_column($relations, 'id'));

        self::assertFalse($relations[0]['intersects'] ?? null);
        self::assertTrue($relations[1]['intersects'] ?? null);
        self::assertFalse($relations[1]['contains'] ?? null);
        self::assertTrue($relations[2]['contains'] ?? null);
    }

    public function testAPointSubjectAnswersWhichParcelHoldsIt(): void
    {
        $response = $this->intersections(
            ['type' => 'Point', 'coordinates' => [1.5, 1.5]],
            [
                ['id' => 'holds-it', 'geometry' => self::geoJson([[[1, 1], [2, 1], [2, 2], [1, 2], [1, 1]]])],
                ['id' => 'elsewhere', 'geometry' => self::geoJson([[[9, 9], [10, 9], [10, 10], [9, 10], [9, 9]]])],
            ],
        );

        self::assertSame(200, $response->getStatusCode());

        $relations = $this->relationsOf($response);
        self::assertTrue($relations[0]['within'] ?? null);
        self::assertTrue($relations[0]['intersects'] ?? null);
        // A point contains nothing, whatever it sits inside.
        self::assertFalse($relations[0]['contains'] ?? null);
        self::assertFalse($relations[1]['within'] ?? null);
    }

    public function testGeographicRelationsAreAnsweredButDistanceIsWithheld(): void
    {
        // Topology is the same question in degrees; distance is not, and a
        // number in degrees presented as a distance is the failure this
        // withholding exists to prevent.
        $response = $this->intersections(
            self::geoJson([[[3.06, 50.63], [3.07, 50.63], [3.07, 50.64], [3.06, 50.64], [3.06, 50.63]]]),
            [[
                'id' => 'neighbour',
                'geometry' => self::geoJson(
                    [[[3.065, 50.635], [3.075, 50.635], [3.075, 50.645], [3.065, 50.645], [3.065, 50.635]]],
                ),
            ]],
            'GEOGRAPHIC',
        );

        self::assertSame(200, $response->getStatusCode());

        $relation = $this->relationsOf($response)[0] ?? [];
        self::assertTrue($relation['intersects'] ?? null);
        self::assertArrayHasKey('distance', $relation);
        self::assertNull($relation['distance']);
    }

    public function testThereIsNoBufferEndpoint(): void
    {
        // Deliberate, and asserted so that adding one is a decision rather
        // than an accident: core PostgreSQL has no buffer, and §19 names
        // buffers among the things a spatial extension is for.
        $response = $this->request(
            'POST',
            '/api/v1/geometry/buffer',
            self::headersFor('mia-token'),
            $this->json(['crs' => 'PROJECTED', 'geometry' => self::geoJson(self::RECTANGLE), 'distance' => 5]),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param list<list<list<float|int>>> $rings
     */
    private function measure(array $rings, string $token = 'mia-token', string $crs = 'PROJECTED'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/geometry/measure',
            self::headersFor($token),
            $this->json(['crs' => $crs, 'geometry' => self::geoJson($rings)]),
        );
    }

    /**
     * @param array<string, mixed>       $subject
     * @param list<array<string, mixed>> $candidates
     */
    private function intersections(array $subject, array $candidates, string $crs = 'PROJECTED'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/geometry/intersections',
            self::headersFor('mia-token'),
            $this->json(['crs' => $crs, 'subject' => $subject, 'candidates' => $candidates]),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function headersFor(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
    }

    /**
     * @param list<list<list<float|int>>> $rings
     *
     * @return array<string, mixed>
     */
    private static function geoJson(array $rings): array
    {
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /**
     * @return array<string, mixed>
     */
    private function measurementOf(ResponseInterface $response): array
    {
        $measurement = $this->decode($response)['measurement'] ?? null;

        self::assertIsArray($measurement);

        /** @var array<string, mixed> $measurement */
        return $measurement;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationsOf(ResponseInterface $response): array
    {
        $relations = $this->decode($response)['relations'] ?? null;

        self::assertIsArray($relations);

        /** @var list<array<string, mixed>> $relations */
        return $relations;
    }

    private function requirementOf(ResponseInterface $response): string
    {
        $details = $this->errorOf($response)['details'] ?? null;

        self::assertIsArray($details);

        $requirement = $details['requirement'] ?? null;

        self::assertIsString($requirement);

        return $requirement;
    }

    /**
     * A JSON number reaches PHP as int or float depending on whether it had a
     * fractional part, so an area of exactly 12 arrives as `12`.
     */
    private static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        // `assertTrue(is_int($v) || is_float($v))` would read the same and
        // narrow nothing: PHPUnit asserts the condition, not the variable, so
        // the cast after it would be a cast of mixed.
        self::fail('Expected a JSON number, got ' . get_debug_type($value) . '.');
    }
}
