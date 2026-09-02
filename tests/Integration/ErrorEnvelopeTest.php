<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Http\RequestId;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\User\Domain\UserDirectory;
use App\User\Infrastructure\InMemoryUserDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The error contract of Architecture V2 §10.4 is consumed by the generated
 * TypeScript client, so its shape is a promise rather than an implementation
 * detail. These tests pin it.
 */
#[CoversNothing]
final class ErrorEnvelopeTest extends ApiTestCase
{
    private const CONTEXT_HEADERS = [
        'Authorization' => 'Bearer valid-token',
        'X-Product' => 'atlas',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->override([
            // Provisioned ids are the subjects, so fixtures stay readable.
            UserDirectory::class => new InMemoryUserDirectory(),

            AuthProvider::class => new FakeAuthProvider(['valid-token' => 'user-1']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product('prod-atlas', 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership('tenant-1', 'user-1', 'prod-atlas', ['USER']),
            ]),
        ]);
    }

    public function testUnknownRouteReturns404InTheDocumentedEnvelope(): void
    {
        $response = $this->request('GET', '/api/v1/does-not-exist', self::CONTEXT_HEADERS);
        $error = $this->errorOf($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $error['code'] ?? null);
        self::assertArrayHasKey('message', $error);
        self::assertArrayHasKey('details', $error);
        self::assertArrayHasKey('request_id', $error);
    }

    /**
     * The context chain runs before routing, so an anonymous caller cannot
     * tell a route that does not exist from one it simply may not reach.
     * Answering 404 here would hand out the route map.
     */
    public function testUnknownRouteIsIndistinguishableFromAProtectedOneWhenAnonymous(): void
    {
        $unknown = $this->request('GET', '/api/v1/does-not-exist');
        $protected = $this->request('GET', '/api/v1/me');

        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame($protected->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(
            $this->errorOf($protected)['code'] ?? null,
            $this->errorOf($unknown)['code'] ?? null,
        );
    }

    /**
     * The public route is exempt from the context chain, so routing answers
     * it directly — which is what makes 405 reachable without credentials.
     */
    public function testWrongMethodReturns405AndAdvertisesAllowedMethods(): void
    {
        $response = $this->request('DELETE', '/api/v1/health');
        $error = $this->errorOf($response);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('METHOD_NOT_ALLOWED', $error['code'] ?? null);
        self::assertSame(['allowed' => ['GET']], $error['details'] ?? null);
    }

    public function testErrorBodyCarriesTheSameCorrelationIdAsTheHeader(): void
    {
        $response = $this->request('GET', '/api/v1/nope', [
            RequestId::HEADER => 'correlate-me-please',
        ]);

        self::assertSame('correlate-me-please', $this->errorOf($response)['request_id'] ?? null);
        self::assertSame('correlate-me-please', $response->getHeaderLine(RequestId::HEADER));
    }
}
