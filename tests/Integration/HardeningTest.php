<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Context\RoutePolicy;
use App\Shared\Http\Middleware\CorsMiddleware;
use App\Shared\Throttle\RateLimitMiddleware;
use App\Throttle\Infrastructure\PostgresRateLimiter;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §31's two edge defences, through the real pipeline.
 *
 * The limiter counts in PostgreSQL, so a double would be a second
 * implementation of the upsert that makes the count safe — which is the part
 * worth testing. CORS needs no database and is here anyway, because what it
 * has to get right is its position in the pipeline: an error response that
 * lost its CORS headers shows a developer a network error instead of a 403.
 *
 * The limits are shrunk to three and two rather than the configured six
 * hundred; a test that had to send six hundred requests to prove a limit
 * would be a test nobody runs.
 */
#[CoversNothing]
final class HardeningTest extends DatabaseApiTestCase
{
    private const LIMIT = 3;
    private const PUBLIC_LIMIT = 2;
    private const WINDOW = 60;

    private const ORIGIN = 'https://app.test';

    protected function setUp(): void
    {
        parent::setUp();

        $policy = $this->container()->get(RoutePolicy::class);
        self::assertInstanceOf(RoutePolicy::class, $policy);

        $this->override([
            RateLimitMiddleware::class => new RateLimitMiddleware(
                new PostgresRateLimiter($this->connection),
                $policy,
                self::WINDOW,
                self::LIMIT,
                self::PUBLIC_LIMIT,
                [],
            ),
            CorsMiddleware::class => new CorsMiddleware([self::ORIGIN]),
        ]);
    }

    // --- Rate limiting ---------------------------------------------------------

    public function testTheRequestAfterTheLimitIsRefused(): void
    {
        $this->spendTheAllowance();

        $refused = $this->health();

        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('RATE_LIMITED', $this->errorOf($refused)['code'] ?? null);
    }

    /**
     * A client told only "too many" can do nothing but guess, and a guessing
     * client retries too soon — which is the load the limit exists to shed,
     * arriving a second later.
     */
    public function testTheRefusalSaysWhenToComeBack(): void
    {
        $this->spendTheAllowance();
        $response = $this->health();

        self::assertSame(429, $response->getStatusCode());

        $retryAfter = $response->getHeaderLine('Retry-After');
        self::assertNotSame('', $retryAfter);
        // Never zero: that would invite an immediate retry.
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertLessThanOrEqual(self::WINDOW, (int) $retryAfter);
    }

    public function testTheRefusalStillCarriesTheCorrelationId(): void
    {
        $this->spendTheAllowance();
        $response = $this->health();

        self::assertSame(429, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertArrayHasKey('request_id', $this->errorOf($response));
    }

    public function testTwoAddressesDoNotSpendEachOthersAllowance(): void
    {
        $this->spendTheAllowance('198.51.100.1');

        // The first client is out; a different one has not started.
        self::assertSame(429, $this->health('198.51.100.1')->getStatusCode());
        self::assertSame(200, $this->health('198.51.100.2')->getStatusCode());
    }

    /**
     * A webhook storm must not lock out the application. Sharing one counter
     * between the public surface and the rest would be exactly that outage,
     * arriving through the door the limit was meant to guard.
     */
    public function testThePublicSurfaceDoesNotSpendTheApplicationsAllowance(): void
    {
        $this->spendTheAllowance();

        self::assertSame(429, $this->health()->getStatusCode());

        // The same address, deliberately: sharing a counter is exactly what
        // this rules out. Unauthenticated, so a 401 — but reaching the
        // context chain at all means the limiter let it through, on a count
        // the public surface had not already spent.
        self::assertSame(401, $this->request(
            'GET',
            '/api/v1/me',
            [],
            null,
            ['REMOTE_ADDR' => '203.0.113.1'],
        )->getStatusCode());
    }

    // --- CORS ------------------------------------------------------------------

    public function testAnUnknownOriginGetsNoCorsHeadersAtAll(): void
    {
        $response = $this->health(origin: 'https://evil.test');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testAnAllowedOriginIsEchoedAndNeverWildcarded(): void
    {
        $response = $this->health(origin: self::ORIGIN);

        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        // A wildcard cannot carry credentials, and would let any page on the
        // internet call this API as a signed-in user.
        self::assertNotSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testEveryResponseVariesOnOrigin(): void
    {
        // The body is the same either way; the headers are not, and a cache
        // that missed that would hand one tenant's allowed origin to another.
        // Origin from CORS, and the headers that decide an answer from the
        // no-shared-cache rule: both, on every response, in one Vary.
        self::assertStringContainsString('Origin', $this->health()->getHeaderLine('Vary'));
        self::assertStringContainsString('Origin', $this->health(origin: self::ORIGIN)->getHeaderLine('Vary'));
        self::assertStringContainsString('Authorization', $this->health()->getHeaderLine('Vary'));
    }

    public function testAPreflightIsAnsweredWithoutReachingTheApplication(): void
    {
        $response = $this->preflight(self::ORIGIN);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertNotSame('', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testAPreflightFromAnUnknownOriginIsAnsweredWithNothing(): void
    {
        $response = $this->preflight('https://evil.test');

        // 204 rather than an error: the browser blocks the real request when
        // the headers are absent, and refusing loudly would tell a probing
        // page which origins are configured.
        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * The reason CORS sits above the error handler. Without this a browser
     * shows the developer a network error rather than the 401 that explains
     * what went wrong.
     */
    public function testAnErrorResponseStillCarriesTheCorsHeaders(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/me',
            ['Origin' => self::ORIGIN],
            null,
            ['REMOTE_ADDR' => '203.0.113.7'],
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * Uses up exactly the public allowance, leaving the next request over it.
     */
    private function spendTheAllowance(string $address = '203.0.113.1'): void
    {
        for ($i = 0; $i < self::PUBLIC_LIMIT; $i++) {
            self::assertSame(200, $this->health($address)->getStatusCode());
        }
    }

    private function health(string $address = '203.0.113.1', string $origin = ''): ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/v1/health',
            $origin === '' ? [] : ['Origin' => $origin],
            null,
            ['REMOTE_ADDR' => $address],
        );
    }

    private function preflight(string $origin): ResponseInterface
    {
        return $this->request(
            'OPTIONS',
            '/api/v1/health',
            [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'Authorization',
            ],
            null,
            ['REMOTE_ADDR' => '203.0.113.99'],
        );
    }
}
