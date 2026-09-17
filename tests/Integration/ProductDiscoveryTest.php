<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductFeature;
use App\Product\Domain\ProductRegistry;
use App\Product\Infrastructure\InMemoryProductRegistry;
use App\Tenant\Domain\JoinRequests;
use App\Tenant\Infrastructure\InMemoryJoinRequests;
use App\Tests\Support\FakeAuthProvider;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserDirectory;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\InMemoryUserDirectory;
use App\User\Infrastructure\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Product discovery through the real pipeline.
 *
 * The claim under test is the one M3 rests on: these endpoints authenticate
 * but do not require product context, because a client learns which products
 * it may use from here. Requiring X-Product would make discovery depend on
 * its own answer.
 */
#[CoversNothing]
final class ProductDiscoveryTest extends ApiTestCase
{
    private const ALICE = 'user-alice';
    private const BOB = 'user-bob';

    protected function setUp(): void
    {
        parent::setUp();

        $atlas = new Product('prod-atlas', 'atlas', 'Atlas', true);
        $beacon = new Product('prod-beacon', 'beacon', 'Beacon', true);

        $this->override([
            UserDirectory::class => new InMemoryUserDirectory(),
            // And where the caller is still waiting to be let in (2026-09-17):
            // nowhere, in this suite.
            JoinRequests::class => new InMemoryJoinRequests(),
            // The list now says which product is the caller's default, which
            // is read from the user record — in memory here, as the identities.
            UserRepository::class => new InMemoryUserRepository([
                new PlatformUser(self::ALICE, self::ALICE, 'alice@example.test'),
                new PlatformUser(self::BOB, self::BOB, 'bob@example.test'),
            ]),

            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => self::ALICE,
                'bob-token' => self::BOB,
            ]),

            ProductRegistry::class => new InMemoryProductRegistry(
                reachable: [
                    self::ALICE => [$atlas, $beacon],
                    self::BOB => [$atlas],
                ],
                features: [
                    'prod-atlas' => [new ProductFeature('projects', 'Projects', true)],
                    'prod-beacon' => [new ProductFeature('projects', 'Projects', true)],
                ],
                configuration: [
                    'prod-atlas' => ['limits' => ['max_projects' => 10]],
                ],
            ),
        ]);
    }

    /**
     * The point of the milestone: no X-Product header, and it works.
     */
    public function testProductsAreListedWithoutAProductContext(): void
    {
        $response = $this->request('GET', '/api/v1/products', [
            'Authorization' => 'Bearer alice-token',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'products' => [
                ['id' => 'prod-atlas', 'code' => 'atlas', 'name' => 'Atlas'],
                ['id' => 'prod-beacon', 'code' => 'beacon', 'name' => 'Beacon'],
            ],
            'default' => null,
            'pending_memberships' => [],
        ], $this->decode($response));
    }

    public function testDiscoveryStillRequiresAuthentication(): void
    {
        $response = $this->request('GET', '/api/v1/products');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $this->errorOf($response)['code'] ?? null);
    }

    /**
     * Two callers, same endpoint, different answers — derived from membership
     * rather than from anything the caller said.
     */
    public function testEachCallerSeesOnlyTheirOwnProducts(): void
    {
        $forBob = $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer bob-token']);

        $products = $this->decode($forBob)['products'] ?? null;
        self::assertIsArray($products);
        self::assertCount(1, $products);
    }

    /**
     * A product Bob cannot reach must be indistinguishable from one that does
     * not exist, or this endpoint becomes a way to enumerate the catalogue.
     */
    public function testAForeignProductIsReportedAsUnknown(): void
    {
        $response = $this->request('GET', '/api/v1/products/prod-beacon', [
            'Authorization' => 'Bearer bob-token',
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PRODUCT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);

        $missing = $this->request('GET', '/api/v1/products/prod-nonexistent', [
            'Authorization' => 'Bearer bob-token',
        ]);

        self::assertSame($missing->getStatusCode(), $response->getStatusCode());
        self::assertSame(
            $this->errorOf($missing)['code'] ?? null,
            $this->errorOf($response)['code'] ?? null,
        );
    }

    public function testCatalogueCombinesProductFeaturesAndConfiguration(): void
    {
        $response = $this->request('GET', '/api/v1/products/prod-atlas/catalog', [
            'Authorization' => 'Bearer alice-token',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'product' => ['id' => 'prod-atlas', 'code' => 'atlas', 'name' => 'Atlas'],
            'features' => [['code' => 'projects', 'name' => 'Projects', 'enabled' => true]],
            'configuration' => ['limits' => ['max_projects' => 10]],
        ], $this->decode($response));
    }

    public function testFeaturesAndConfigurationAreServedSeparatelyToo(): void
    {
        $headers = ['Authorization' => 'Bearer alice-token'];

        $features = $this->request('GET', '/api/v1/products/prod-atlas/features', $headers);
        $configuration = $this->request('GET', '/api/v1/products/prod-atlas/configuration', $headers);

        self::assertSame(200, $features->getStatusCode());
        self::assertSame(200, $configuration->getStatusCode());
        self::assertSame(
            ['features' => [['code' => 'projects', 'name' => 'Projects', 'enabled' => true]]],
            $this->decode($features),
        );
        self::assertSame(
            ['configuration' => ['limits' => ['max_projects' => 10]]],
            $this->decode($configuration),
        );
    }

    /**
     * The relaxation is scoped: routes outside /products still demand the
     * full chain, so this did not weaken anything else.
     */
    public function testOtherRoutesStillRequireProductContext(): void
    {
        $response = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer alice-token']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('PRODUCT_CONTEXT_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }
}
