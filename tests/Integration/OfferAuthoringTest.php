<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
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
 * §12 as a writable catalogue, against the real database.
 *
 * The rules under test are constraints and triggers, not service code, so a
 * doubled repository would prove nothing: "a published version is frozen" is
 * true because PostgreSQL refuses the UPDATE, and the only way to see that is
 * to let PostgreSQL refuse it.
 *
 * §37.4 order: who may write, then what is refused, then what is built.
 */
#[CoversNothing]
final class OfferAuthoringTest extends DatabaseApiTestCase
{
    private const ACME = 'tenant-acme';

    private string $product = '';
    private string $plan = '';
    private string $feature = '';
    private string $otherProduct = '';
    private string $otherPlan = '';
    private string $author = '';
    private string $reader = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Real user rows: a membership is keyed by user id, and the token
        // carries the auth subject, so anything else here would match nothing
        // and every request would be a 403 for the wrong reason.
        $this->author = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id",
        );
        $this->reader = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-raj', 'raj@acme.test') RETURNING id",
        );

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:p, 'pro', 'Pro', 10) RETURNING id",
            ['p' => $this->product],
        );
        $this->feature = $this->id(
            'INSERT INTO features (code, name, kind)'
            . " VALUES ('projects', 'Projects', 'QUOTA') RETURNING id",
        );

        // A whole second product, so "belongs to this product" is tested
        // against something real rather than against a fabricated uuid.
        $this->otherProduct = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('orbit', 'Orbit', true) RETURNING id",
        );
        $this->otherPlan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:p, 'basic', 'Basic', 1) RETURNING id",
            ['p' => $this->otherProduct],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'raj-token' => 'sub-raj',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                // Ada writes the catalogue; Raj reads it. Same tenant, same
                // product, different permission — which is the only thing
                // separating them.
                new TenantMembership(
                    self::ACME,
                    $this->author,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['catalog.read', 'catalog.manage'],
                ),
                new TenantMembership(self::ACME, $this->reader, $this->product, ['USER'], ['catalog.read']),
            ]),
        ]);
    }

    // --- Who may write ------------------------------------------------------

    public function testReadingTheCatalogueDoesNotLetYouPriceIt(): void
    {
        $response = $this->create(['code' => 'pro-monthly'], 'raj-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testDraftsAreNotVisibleToSomeoneWhoMayOnlyRead(): void
    {
        $offerId = $this->createdOffer();

        $response = $this->request(
            'GET',
            '/api/v1/offers/' . $offerId . '/versions',
            $this->headers('raj-token'),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testADraftIsNotOnSale(): void
    {
        $this->createdOffer();

        // The offer exists and has a version, but nothing is published — so
        // the sale surface reports nothing to buy.
        $listed = $this->decode($this->request('GET', '/api/v1/offers', $this->headers()));

        self::assertSame([], $listed['offers'] ?? null);
    }

    // --- What is refused ----------------------------------------------------

    public function testAPlanFromAnotherProductIsRefused(): void
    {
        $response = $this->create(['plan_id' => $this->otherPlan]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PLAN_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testADuplicateCodeIsRefused(): void
    {
        $this->create(['code' => 'pro-monthly']);
        $response = $this->create(['code' => 'pro-monthly']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('OFFER_CODE_TAKEN', $this->errorOf($response)['code'] ?? null);
    }

    public function testACommitmentLongerThanTheTermIsRefused(): void
    {
        $response = $this->create(['terms' => ['term_months' => 12, 'commitment_months' => 24]]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAnUnknownBillingPeriodIsRefusedWithTheListOfKnownOnes(): void
    {
        $response = $this->create(['billing_period' => 'MONTLY']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MONTHLY', $this->requirementOf($response));
    }

    public function testPublishingTwiceIsRefused(): void
    {
        $offerId = $this->createdOffer();
        self::assertSame(200, $this->publish($offerId, 1)->getStatusCode());

        $again = $this->publish($offerId, 1);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('VERSION_NOT_PUBLISHABLE', $this->errorOf($again)['code'] ?? null);
    }

    public function testTwoVersionsMayNotBeOnSaleOverTheSamePeriod(): void
    {
        $offerId = $this->createdOffer();
        $this->publish($offerId, 1);
        $this->addVersion($offerId, ['price_minor_units' => 4900]);

        $clash = $this->publish($offerId, 2);

        // The database refused this, not the service: the first version's
        // window is open-ended, so the second overlaps it forever.
        self::assertSame(409, $clash->getStatusCode());
        self::assertSame('OFFER_ALREADY_ON_SALE', $this->errorOf($clash)['code'] ?? null);
    }

    public function testAPublishedPriceCannotBeRewrittenThroughAnyEndpoint(): void
    {
        $offerId = $this->createdOffer();
        $this->publish($offerId, 1);

        // There is no endpoint that edits a published version's price, and
        // that absence is the mechanism. PATCH takes a name and nothing else,
        // so a caller who sends a price gets a rename and no price change.
        $response = $this->request(
            'PATCH',
            '/api/v1/offers/' . $offerId,
            $this->headers(),
            $this->json(['name' => 'Renamed', 'price_minor_units' => 1]),
        );

        self::assertSame(200, $response->getStatusCode());

        self::assertSame('Renamed', $this->offerOf($response)['name'] ?? null);

        self::assertSame(2900, $this->partOf($response, 'price')['minor_units'] ?? null);
    }

    // --- What is built ------------------------------------------------------

    public function testAnOfferIsBornWithOneDraftVersion(): void
    {
        $response = $this->create(['code' => 'pro-monthly', 'name' => 'Pro Monthly']);

        self::assertSame(201, $response->getStatusCode());

        $versions = $this->versionsOf($response);
        self::assertCount(1, $versions);
        self::assertSame(1, $versions[0]['version'] ?? null);
        self::assertSame('DRAFT', $versions[0]['status'] ?? null);
        self::assertSame(['minor_units' => 2900, 'currency' => 'EUR'], $versions[0]['price'] ?? null);

        // Present and null: an open commercial window, not a missing field.
        self::assertArrayHasKey('valid_until', $versions[0]);
        self::assertNull($versions[0]['valid_until']);
    }

    public function testVersionsAreNumberedByTheDatabaseAndNotByTheCaller(): void
    {
        $offerId = $this->createdOffer();

        // The body names no version, and could not: the number is max + 1,
        // assigned by the statement that writes the row.
        $second = $this->addVersion($offerId, ['price_minor_units' => 3900, 'version' => 99]);
        $third = $this->addVersion($offerId, ['price_minor_units' => 4900]);

        self::assertSame(3, $this->newestVersion($third)['version'] ?? null);
        self::assertSame(2, $this->newestVersion($second)['version'] ?? null);
    }

    public function testPublishingPutsTheVersionOnSale(): void
    {
        $offerId = $this->createdOffer();
        $this->publish($offerId, 1);

        $listed = $this->decode($this->request('GET', '/api/v1/offers', $this->headers()));
        $offers = $listed['offers'] ?? null;

        self::assertIsArray($offers);
        self::assertCount(1, $offers);
    }

    public function testGrantsTravelWithTheVersion(): void
    {
        $response = $this->create([
            'grants' => [['feature_id' => $this->feature, 'limit' => 10]],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $grants = $this->partOf($response, 'grants');
        self::assertCount(1, $grants);

        $grant = $grants[0] ?? null;
        self::assertIsArray($grant);
        self::assertSame(10, $grant['limit'] ?? null);
    }

    /**
     * A retired feature cannot be granted by a new offer, and the refusal
     * takes the whole version with it.
     *
     * This used to be "a feature from another product", which since
     * 2026-09-24 is not a thing: there is one list, and every product's
     * offers grant out of it. What replaced the product filter is the
     * `active` flag — retiring is how the platform stops something being
     * sold, without deleting a row that live entitlements name.
     */
    public function testARetiredFeatureIsRefusedAndTakesTheVersionWithIt(): void
    {
        $retired = $this->id(
            'INSERT INTO features (code, name, kind, active)'
            . " VALUES ('seats', 'Seats', 'QUOTA', false) RETURNING id",
        );

        $response = $this->create(['grants' => [['feature_id' => $retired, 'limit' => 5]]]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('FEATURE_NOT_FOUND', $this->errorOf($response)['code'] ?? null);

        // The whole call was one transaction, so no half-built offer is left
        // behind granting nothing.
        $offers = $this->connection->fetchOne('SELECT count(*) FROM offers');
        self::assertSame('0', (string) (is_scalar($offers) ? $offers : 'not counted'));
    }

    public function testTermsAreCarriedOntoTheVersion(): void
    {
        $response = $this->create([
            'terms' => [
                'term_months' => 24,
                'commitment_months' => 12,
                'cancellation_policy' => 'AT_COMMITMENT_END',
                'renewal' => 'ENDS_AT_TERM',
                'early_termination' => 'CHARGE_REMAINING',
                'notice_days' => 30,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $terms = $this->partOf($response, 'terms');
        self::assertSame(24, $terms['term_months'] ?? null);
        self::assertSame(12, $terms['commitment_months'] ?? null);
        self::assertSame('CHARGE_REMAINING', $terms['early_termination'] ?? null);
    }

    public function testAnOfferWithNoTermsIsMonthToMonth(): void
    {
        $terms = $this->partOf($this->create([]), 'terms');

        // The key must be present *and* null: `?? null` would pass either way,
        // which is the difference between "open-ended" and "not reported".
        self::assertArrayHasKey('term_months', $terms);
        self::assertNull($terms['term_months']);
        self::assertSame(0, $terms['commitment_months'] ?? null);
        self::assertSame('ANYTIME', $terms['cancellation_policy'] ?? null);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function create(array $overrides, string $token = 'ada-token'): ResponseInterface
    {
        $body = $overrides + [
            'code' => 'pro-' . bin2hex(random_bytes(4)),
            'name' => 'Pro',
            'plan_id' => $this->plan,
            'billing_period' => 'MONTHLY',
            'price_minor_units' => 2900,
            'currency' => 'EUR',
        ];

        return $this->request('POST', '/api/v1/offers', $this->headers($token), $this->json($body));
    }

    private function createdOffer(): string
    {
        $id = $this->offerOf($this->create([]))['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addVersion(string $offerId, array $overrides): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/offers/' . $offerId . '/versions',
            $this->headers(),
            $this->json($overrides + [
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ]),
        );
    }

    private function publish(string $offerId, int $version): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/offers/' . $offerId . '/publish',
            $this->headers(),
            $this->json(['version' => $version]),
        );
    }

    /**
     * The `offer` object, narrowed once so no assertion has to reach through
     * two offsets on mixed to get at a value.
     *
     * @return array<string, mixed>
     */
    private function offerOf(ResponseInterface $response): array
    {
        $offer = $this->decode($response)['offer'] ?? null;

        self::assertIsArray($offer);

        /** @var array<string, mixed> $offer */
        return $offer;
    }

    /**
     * The newest version, narrowed once.
     *
     * @return array<string, mixed>
     */
    private function newestVersion(ResponseInterface $response): array
    {
        $version = $this->versionsOf($response)[0] ?? null;

        self::assertIsArray($version);

        /** @var array<string, mixed> $version */
        return $version;
    }

    /**
     * One nested object of the newest version — `price`, `terms`, `grants`.
     *
     * @return array<string, mixed>
     */
    private function partOf(ResponseInterface $response, string $key): array
    {
        $part = $this->newestVersion($response)[$key] ?? null;

        self::assertIsArray($part);

        /** @var array<string, mixed> $part */
        return $part;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versionsOf(ResponseInterface $response): array
    {
        $versions = $this->offerOf($response)['versions'] ?? null;

        self::assertIsArray($versions);

        /** @var list<array<string, mixed>> $versions */
        return $versions;
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
     * @return array<string, string>
     */
    private function headers(string $token = 'ada-token'): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
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
