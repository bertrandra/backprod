<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Controller\RefreshCookie;
use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Shared\Logging\ErrorLogLogger;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in, against the real database and the real pipeline (U12).
 *
 * The whole point of U12 is that this path exists without an external provider, so
 * the test drives it end to end: a password becomes a token, the token opens a
 * protected endpoint, the cookie renews it, and each of those is a real HTTP
 * request through the real middleware chain.
 *
 * The issuer and the verifier are overridden with a known secret rather than
 * configured through the environment. `AUTH_SIGNING_SECRET` is read once when the
 * container is built, and a test that set it globally would change how every other
 * test in the process authenticates.
 */
final class SignInTest extends DatabaseApiTestCase
{
    private const SECRET = 'a-test-signing-secret-nobody-deploys';
    private const PASSWORD = 'correct horse battery staple';

    private string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $logger = new ErrorLogLogger();

        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer(
                self::SECRET,
                LocalTokens::DEFAULT_ISSUER,
                LocalTokens::DEFAULT_AUDIENCE,
            ),
            AuthProvider::class => new LocalJwtAuthProvider(
                self::SECRET,
                LocalTokens::DEFAULT_ISSUER,
                LocalTokens::DEFAULT_AUDIENCE,
                $logger,
            ),
        ]);

        // A user, and the credential they prove themselves with. `auth_subject` is
        // `local:<id>` — the same `prefix:id` shape erasure already uses — so a
        // token this platform issued resolves to this row and only this row.
        $inserted = $this->connection->fetchOne(
            "INSERT INTO users (auth_subject, email, display_name)
             VALUES ('pending', 'ada@acme.test', 'Ada') RETURNING id",
        );

        self::assertIsString($inserted);
        $this->userId = $inserted;

        $this->connection->executeStatement(
            "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
            ['id' => $this->userId],
        );

        $this->connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            [
                'id' => $this->userId,
                'email' => 'ada@acme.test',
                'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            ],
        );
    }

    private function signIn(string $email = 'ada@acme.test', string $password = self::PASSWORD): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/auth/token',
            body: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The error envelope, minus the one field that differs by design.
     *
     * @return array<string, mixed>
     */
    private function errorWithoutRequestId(ResponseInterface $response): array
    {
        $error = $this->decode($response)['error'] ?? [];

        self::assertIsArray($error);
        unset($error['request_id']);

        /** @var array<string, mixed> $error */
        return $error;
    }

    /**
     * A bare request, for the two tests that build a cookie rather than earn
     * one. `https` so `Secure` is added, as it is on any real deployment.
     */
    private static function aRequest(): ServerRequestInterface
    {
        return (new ServerRequest())->withUri(new Uri('https://api.test/'));
    }

    private function cookieFrom(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, RefreshCookie::NAME . '=')) {
                $value = explode(';', substr($header, strlen(RefreshCookie::NAME) + 1))[0];

                return $value;
            }
        }

        return '';
    }

    public function testAPasswordBecomesAnAccessToken(): void
    {
        $response = $this->signIn();

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);

        self::assertSame('Bearer', $body['token_type'] ?? null);
        self::assertIsString($body['access_token'] ?? null);
        self::assertSame(LocalJwtTokenIssuer::DEFAULT_LIFETIME, $body['expires_in'] ?? null);
    }

    public function testTheTokenOpensAProtectedEndpointAsTheRightPerson(): void
    {
        $body = $this->decode($this->signIn());
        $token = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        // `/api/v1/products` rather than `/api/v1/me`, and the first attempt at this
        // test taught the difference: `/me` is behind the *full* chain, so it needs
        // a product and a tenant and answered 400 for want of an `X-Product`
        // header — nothing to do with the token. Discovery is IDENTITY_ONLY
        // precisely because a client cannot name a product before it has one, which
        // makes it the endpoint that asks only the question under test.
        $reached = $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token]);

        self::assertSame(200, $reached->getStatusCode());

        // And it resolved to the *existing* row rather than creating another.
        //
        // `PostgresUserDirectory` upserts on `auth_subject`, so a token whose `sub`
        // carried the user id instead of the subject would still authenticate — and
        // would silently mint a second user row for the same person, who would then
        // own none of their own tenants. One row is the assertion that catches it.
        $users = $this->connection->fetchOne('SELECT count(*) FROM users');

        self::assertIsNumeric($users);
        self::assertSame(1, (int) $users);
    }

    public function testTheRefreshTokenIsNeverInTheBody(): void
    {
        $response = $this->signIn();

        // If it were, a script could read it and store it somewhere worse, which
        // would give back exactly the exposure the cookie exists to remove.
        self::assertArrayNotHasKey('refresh_token', $this->decode($response));
        self::assertNotSame('', $this->cookieFrom($response));
    }

    public function testTheCookieIsUnreadableByScriptAndScopedToTheAuthEndpoints(): void
    {
        $header = $this->signIn()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
        self::assertStringContainsString('Path=' . RefreshCookie::PATH, $header);
        // The test harness speaks https, as any real deployment does.
        self::assertStringContainsString('Secure', $header);

        // And **host-only unless a deployment asks otherwise** (2026-09-24):
        // no `Domain`, so the browser returns it to exactly the host that set
        // it. This is the default because widening where a month-long
        // credential may travel is a decision somebody makes on purpose.
        self::assertStringNotContainsString('Domain=', $header);
    }

    /**
     * ADR-051 §3's single sign-on, which `SameSite` alone never delivered.
     *
     * Strict is a rule about *site*, so `plan.raillard.org` calling the
     * platform is allowed — and a cookie with no `Domain` is **host-only**,
     * which no subdomain ever receives whatever SameSite says. Somebody who
     * had just bought a seat was asked for their password on the way to it.
     *
     * Asserted on the built header rather than through a request, because
     * what was wrong was the *attribute*, and a test that signed in twice
     * would prove the wiring and not the cookie.
     */
    public function testAConfiguredDomainWidensTheCookieToSiblingSubdomains(): void
    {
        $response = RefreshCookie::set(
            new EmptyResponse(204),
            self::aRequest(),
            'a-token',
            3600,
            RefreshCookie::domainFrom('raillard.org'),
        );

        self::assertStringContainsString('Domain=raillard.org', $response->getHeaderLine('Set-Cookie'));

        // A leading dot is what half the internet writes and RFC 6265
        // ignores; an operator who writes one gets what they meant.
        self::assertSame('raillard.org', RefreshCookie::domainFrom('.raillard.org'));
        self::assertSame('raillard.org', RefreshCookie::domainFrom('  raillard.org '));

        // Unset, empty and whitespace are all "host-only" — the default a
        // deployment on one host wants, and the one it gets by saying nothing.
        self::assertSame('', RefreshCookie::domainFrom(null));
        self::assertSame('', RefreshCookie::domainFrom(''));
        self::assertSame('', RefreshCookie::domainFrom('   '));
    }

    /**
     * Clearing must repeat every attribute it was set with.
     *
     * A browser matches a replacement by name, domain and path: clear without
     * the `Domain` that set it and it stores a *second*, empty, host-only
     * cookie while the wide one keeps being sent. A sign-out that leaves the
     * credential in the browser is not a sign-out, which is why all four
     * controllers take the domain and not only the two that issue.
     */
    public function testClearingRepeatsTheDomainItWasSetWith(): void
    {
        $cleared = RefreshCookie::clear(
            new EmptyResponse(204),
            self::aRequest(),
            RefreshCookie::domainFrom('raillard.org'),
        );

        $header = $cleared->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Domain=raillard.org', $header);
        self::assertStringContainsString('Max-Age=0', $header);
        self::assertStringContainsString('Path=' . RefreshCookie::PATH, $header);
    }

    public function testAWrongPasswordAndAnUnknownAddressAreAnsweredIdentically(): void
    {
        $wrongPassword = $this->signIn(password: 'not the right password');
        $noSuchPerson = $this->signIn(email: 'nobody@acme.test');

        self::assertSame(401, $wrongPassword->getStatusCode());
        self::assertSame(401, $noSuchPerson->getStatusCode());

        // Identical answers, because the difference between them is how somebody
        // learns which addresses have accounts here. The request id is deliberately
        // excluded: it differs per request by design, and comparing whole bodies —
        // which is what this test did first — asserts that two requests are the same
        // request rather than that two answers are the same answer.
        self::assertSame(
            $this->errorWithoutRequestId($wrongPassword),
            $this->errorWithoutRequestId($noSuchPerson),
        );
    }

    public function testASignedInPersonStaysSignedInThroughTheCookie(): void
    {
        $cookie = $this->cookieFrom($this->signIn());

        $renewed = $this->request(
            'POST',
            '/api/v1/auth/refresh',
            cookies: [RefreshCookie::NAME => $cookie],
        );

        self::assertSame(200, $renewed->getStatusCode());
        self::assertIsString($this->decode($renewed)['access_token'] ?? null);
        // Rotated: the new cookie is a different token, which is what makes reuse
        // of the old one detectable.
        self::assertNotSame($cookie, $this->cookieFrom($renewed));
    }

    public function testRefreshingWithNoCookieIsRefused(): void
    {
        self::assertSame(401, $this->request('POST', '/api/v1/auth/refresh')->getStatusCode());
    }

    public function testReusingASpentRefreshTokenRevokesEverySessionForThatAccount(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $second = $this->cookieFrom($this->request(
            'POST',
            '/api/v1/auth/refresh',
            cookies: [RefreshCookie::NAME => $first],
        ));

        // A third party presenting the token the real client already exchanged.
        $replay = $this->request('POST', '/api/v1/auth/refresh', cookies: [RefreshCookie::NAME => $first]);

        self::assertSame(401, $replay->getStatusCode());

        // And the legitimate client's *current* token is dead too. That is the
        // decision, not a side effect: the two cases are indistinguishable from
        // here, and signing the real person out costs them a sign-in while leaving
        // a thief signed in costs them everything.
        $afterReplay = $this->request('POST', '/api/v1/auth/refresh', cookies: [RefreshCookie::NAME => $second]);

        self::assertSame(401, $afterReplay->getStatusCode());
    }

    public function testSigningOutRevokesTheTokenAndClearsTheCookie(): void
    {
        $cookie = $this->cookieFrom($this->signIn());

        $out = $this->request('POST', '/api/v1/auth/sign-out', cookies: [RefreshCookie::NAME => $cookie]);

        self::assertSame(204, $out->getStatusCode());
        // Emptied, so the browser forgets it.
        self::assertStringContainsString(RefreshCookie::NAME . '=;', $out->getHeaderLine('Set-Cookie'));

        // Revoked server-side, which is the half that matters: clearing the cookie
        // alone would leave the credential valid for anybody holding a copy.
        $reuse = $this->request('POST', '/api/v1/auth/refresh', cookies: [RefreshCookie::NAME => $cookie]);

        self::assertSame(401, $reuse->getStatusCode());
    }

    public function testSigningOutWithNothingToSignOutOfStillSucceeds(): void
    {
        // Somebody whose session already expired is still trying to leave, and
        // answering with an error would be an error message in place of the thing
        // they asked for.
        self::assertSame(204, $this->request('POST', '/api/v1/auth/sign-out')->getStatusCode());
    }

    public function testAnErasedPersonCannotSignIn(): void
    {
        // §15 keeps the users row for legal retention after erasure. A deleted
        // person who can still sign in has not been deleted.
        $this->connection->executeStatement(
            "UPDATE users SET erased_at = now(), email = NULL, display_name = NULL,
                    auth_subject = 'erased:' || id WHERE id = :id",
            ['id' => $this->userId],
        );

        self::assertSame(401, $this->signIn()->getStatusCode());
    }

    public function testAPasswordIsNotTrimmedOnTheWayIn(): void
    {
        $this->connection->executeStatement(
            'UPDATE local_credentials SET password_hash = :hash WHERE user_id = :id',
            ['id' => $this->userId, 'hash' => password_hash('  spaces at both ends  ', PASSWORD_BCRYPT)],
        );

        // A password may legitimately begin or end with a space. Trimming would
        // make this account unusable, and asymmetrically: whichever end trimmed
        // would decide what was stored.
        self::assertSame(200, $this->signIn(password: '  spaces at both ends  ')->getStatusCode());
    }

    public function testADeploymentWithNoSigningSecretSaysSoRatherThanIssuingAForgeableToken(): void
    {
        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer(
                '',
                LocalTokens::DEFAULT_ISSUER,
                LocalTokens::DEFAULT_AUDIENCE,
            ),
        ]);

        $response = $this->signIn();

        // 503 and a code an operator can act on. `JWT::encode` with an empty key
        // produces a well-formed token whose HMAC anybody can recompute, so the
        // alternative to refusing is signing people in with forgeable credentials.
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_NOT_CONFIGURED', $this->errorWithoutRequestId($response)['code'] ?? null);
    }
}
