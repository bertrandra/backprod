<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Controller\RefreshCookie;
use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\PasswordHashing;
use App\Auth\Domain\RefreshRotation;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Auth\Service\Sessions;
use App\Privacy\Domain\ErasureRepository;
use App\Shared\Logging\ErrorLogLogger;
use App\Tests\Support\TestDatabase;
use App\Throttle\Domain\RateLimiter;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function DI\autowire;
use function DI\get;

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
            RefreshRotation::class => new RefreshRotation(self::SECRET),
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

    /**
     * @param string|null $from the client's address; this test's own when null
     */
    private function signIn(string $email = 'ada@acme.test', string $password = self::PASSWORD, ?string $from = null): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/auth/token',
            body: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
            serverParams: $from === null ? [] : ['REMOTE_ADDR' => $from],
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

    /**
     * One address may be tried so many times in a window from one client
     * (2026-10-26, per client since 2026-10-29). Past the bound even the
     * right password is refused from that client — the answer must not
     * depend on whether it was.
     */
    public function testOneAddressIsTriedOnlySoManyTimesInAWindow(): void
    {
        for ($i = 0; $i < Sessions::SIGN_IN_LIMIT; ++$i) {
            self::assertSame(401, $this->signIn('ada@acme.test', 'wrong password')->getStatusCode());
        }

        $refused = $this->signIn();

        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('RATE_LIMITED', $this->errorOf($refused)['code'] ?? null);

        // Another address is untouched.
        self::assertSame(401, $this->signIn('nobody@acme.test', 'wrong password')->getStatusCode());
    }

    /**
     * Only a failure spends the bound (2026-10-29). Counting every attempt
     * meant a person who signs in often — or a script signing in for them —
     * used up their own address's allowance with the right password.
     */
    public function testSigningInSuccessfullySpendsNothing(): void
    {
        for ($i = 0; $i <= Sessions::SIGN_IN_LIMIT; ++$i) {
            self::assertSame(200, $this->signIn()->getStatusCode());
        }

        // And the failures still count from zero.
        for ($i = 0; $i < Sessions::SIGN_IN_LIMIT; ++$i) {
            self::assertSame(401, $this->signIn('ada@acme.test', 'wrong password')->getStatusCode());
        }

        self::assertSame(429, $this->signIn()->getStatusCode());
    }

    /**
     * A stranger's failures lock out the stranger (2026-10-29). Counted per
     * address alone, twenty wrong passwords from anybody kept the owner out
     * of their own account for fifteen minutes.
     */
    public function testAStrangersFailuresDoNotLockTheOwnerOut(): void
    {
        for ($i = 0; $i < Sessions::SIGN_IN_LIMIT; ++$i) {
            self::assertSame(401, $this->signIn('ada@acme.test', 'wrong password', '203.0.113.7')->getStatusCode());
        }

        // The stranger is refused, with the refusal the bound has always given…
        $stranger = $this->signIn(from: '203.0.113.7');

        self::assertSame(429, $stranger->getStatusCode());
        self::assertSame('RATE_LIMITED', $this->errorOf($stranger)['code'] ?? null);

        // …and the owner, from where they are, signs in.
        self::assertSame(200, $this->signIn(from: '198.51.100.20')->getStatusCode());
    }

    /**
     * Guessing spread over many clients, each under the per-client bound,
     * still meets the address's ceiling. Lowered here, because two hundred
     * hashes at the platform's cost would make this the slowest test in the
     * suite; production keeps {@see Sessions::SIGN_IN_CEILING}.
     */
    public function testGuessingFromManyClientsMeetsTheAddressCeiling(): void
    {
        $this->override([
            Sessions::class => autowire(Sessions::class)
                ->constructorParameter('rotation', get(RefreshRotation::class))
                ->constructorParameter('attempts', get(RateLimiter::class))
                ->constructorParameter('signInCeiling', 3),
        ]);

        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $client) {
            self::assertSame(401, $this->signIn('ada@acme.test', 'wrong password', $client)->getStatusCode());
        }

        $refused = $this->signIn(from: '192.0.2.4');

        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('RATE_LIMITED', $this->errorOf($refused)['code'] ?? null);

        // The ceiling is the address's, not the client's.
        self::assertSame(401, $this->signIn('nobody@acme.test', 'wrong password', '192.0.2.4')->getStatusCode());
    }

    /**
     * One cost for every hash (2026-10-29): sign-up wrote cost 10 while the
     * equaliser a miss is verified against was cost 12, so a wrong password
     * on a real account answered faster than one on no account. A hash
     * written at another cost is brought to the platform's on sign-in.
     */
    public function testASignInRehashesAPasswordWrittenAtAnotherCost(): void
    {
        $this->connection->executeStatement(
            'UPDATE local_credentials SET password_hash = :hash WHERE user_id = :id',
            ['id' => $this->userId, 'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10])],
        );

        self::assertSame(200, $this->signIn()->getStatusCode());

        $hash = $this->connection->fetchOne('SELECT password_hash FROM local_credentials WHERE user_id = :id', ['id' => $this->userId]);
        self::assertIsString($hash);
        self::assertSame(PasswordHashing::OPTIONS, password_get_info($hash)['options']);
        self::assertSame(200, $this->signIn()->getStatusCode());
    }

    public function testTheTimingEqualiserCostsWhatARealHashCosts(): void
    {
        $equaliser = PasswordHashing::equaliser();

        self::assertSame('bcrypt', password_get_info($equaliser)['algoName']);
        self::assertSame(PasswordHashing::OPTIONS, password_get_info($equaliser)['options']);
        self::assertSame(
            password_get_info(PasswordHashing::hash('anything'))['options'],
            password_get_info($equaliser)['options'],
        );
        self::assertFalse(PasswordHashing::needsRehash($equaliser));
    }

    /**
     * Erasure ends the way in (2026-10-29): the credential and every live
     * sign-in go with the identity, and an access token still in its hour
     * of life provisions nobody. It used to resolve to a brand new user row
     * carrying the erased address, because erasure renamed the subject the
     * token names.
     */
    public function testAnErasedPersonsLiveTokenProvisionsNobody(): void
    {
        $response = $this->signIn();
        $body = $this->decode($response);
        $token = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
        $cookie = $this->cookieFrom($response);
        self::assertNotSame('', $token);

        $operator = $this->connection->fetchOne(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ops', 'ops@platform.test') RETURNING id",
        );
        self::assertIsString($operator);

        $erasure = $this->container()->get(ErasureRepository::class);
        self::assertInstanceOf(ErasureRepository::class, $erasure);
        $erasure->erase($this->userId, $operator, null);

        self::assertSame(401, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM users WHERE email = 'ada@acme.test'"));
        self::assertSame(2, $this->rowCount('SELECT count(*) FROM users'));

        // Neither the credential nor a session survives.
        self::assertSame(0, $this->rowCount('SELECT count(*) FROM local_credentials WHERE user_id = :id', ['id' => $this->userId]));
        self::assertSame([], $this->liveTokens());
        self::assertSame(401, $this->refresh($cookie)->getStatusCode());
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

    /**
     * @param array<string, string> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function refresh(string $cookie): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/refresh', cookies: [RefreshCookie::NAME => $cookie]);
    }

    /** @return list<string> the hashes of this account's live refresh tokens */
    private function liveTokens(): array
    {
        return array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT token_hash FROM auth_refresh_tokens WHERE user_id = :id AND revoked_at IS NULL',
            ['id' => $this->userId],
        ), 'is_string'));
    }

    /** Moves a rotation into the past: what a clock would do, in one statement. */
    private function ageRotationOf(string $token, int $seconds): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_refresh_tokens SET revoked_at = now() - make_interval(secs => :age) WHERE token_hash = :hash',
            ['age' => $seconds, 'hash' => hash('sha256', $token)],
        );
    }

    /**
     * Two tabs, one cookie, both refreshing — and both are handed the same
     * token (ADR-062).
     *
     * `AUTH_COOKIE_DOMAIN` makes the refresh cookie one credential for the
     * platform and the product beside it, so two tabs waking from sleep both
     * call `/auth/refresh` holding the same token. The replacement is derived
     * from the token, so the second is handed exactly what the first was —
     * and the order the two answers reach the cookie jar cannot matter,
     * because they carry the same value.
     */
    public function testTwoTabsRefreshingWithTheSameCookieAreHandedTheSameToken(): void
    {
        $shared = $this->cookieFrom($this->signIn());

        $firstTab = $this->refresh($shared);
        $secondTab = $this->refresh($shared);

        self::assertSame(200, $firstTab->getStatusCode());
        self::assertSame(200, $secondTab->getStatusCode(), (string) $secondTab->getBody());
        self::assertSame($this->cookieFrom($firstTab), $this->cookieFrom($secondTab));

        // One live token — the one both were handed. Nothing forked.
        self::assertSame([hash('sha256', $this->cookieFrom($firstTab))], $this->liveTokens());
        self::assertSame(200, $this->refresh($this->cookieFrom($secondTab))->getStatusCode());
    }

    /**
     * An answer that never arrived is answered again, however late
     * (ADR-062).
     *
     * A lid closed while `/auth/refresh` was in flight, a reload that aborted
     * it: the server rotated the token and the browser never stored the
     * replacement. An hour later it presents the old one. Under random
     * rotation that was theft, and the account was signed out everywhere;
     * the replacement has never been used, so nobody but the holder of the
     * old token can be asking, and it is handed over again.
     */
    public function testALostAnswerIsAnsweredAgainHoweverLate(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $lost = $this->cookieFrom($this->refresh($first));

        $this->ageRotationOf($first, 3600);

        $again = $this->refresh($first);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($lost, $this->cookieFrom($again));
        self::assertSame([hash('sha256', $lost)], $this->liveTokens());
    }

    /**
     * A request that left before a rotation, arriving just after it, is
     * followed to the live end of the chain (ADR-062, `RACE_WINDOW`).
     *
     * The case derivation alone does not cover: the replacement has already
     * been used — by the product beside the platform, say, which shares the
     * cookie but not the browser's lock — while a request carrying the token
     * before it was on the wire.
     */
    public function testARequestFromJustBeforeARotationIsFollowedToTheLiveEnd(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $second = $this->cookieFrom($this->refresh($first));
        $third = $this->cookieFrom($this->refresh($second));

        $late = $this->refresh($first);

        self::assertSame(200, $late->getStatusCode(), (string) $late->getBody());
        self::assertSame($third, $this->cookieFrom($late));
        self::assertSame([hash('sha256', $third)], $this->liveTokens());
    }

    /**
     * A token presented after its replacement was used, and not in a race,
     * is a copy in somebody else's hands — and that sign-in ends, not the
     * account (ADR-062).
     *
     * The browser that used the replacement stored it, and a cookie jar does
     * not go backwards; only a second holder presents what came before. The
     * family goes: the legitimate client's current token dies with it,
     * because the two are indistinguishable from here. The person's *other*
     * sign-in — another device — is not evidence of anything and stays.
     */
    public function testReplayingATokenWhoseReplacementWasUsedEndsThatSignInOnly(): void
    {
        $elsewhere = $this->cookieFrom($this->signIn());

        $first = $this->cookieFrom($this->signIn());
        $second = $this->cookieFrom($this->refresh($first));
        $third = $this->cookieFrom($this->refresh($second));

        $this->ageRotationOf($second, Sessions::RACE_WINDOW + 60);

        self::assertSame(401, $this->refresh($first)->getStatusCode());

        // The thief and the owner cannot be told apart, so the owner's
        // current token goes too.
        self::assertSame(401, $this->refresh($third)->getStatusCode());

        // The other device was never involved.
        self::assertSame(200, $this->refresh($elsewhere)->getStatusCode());
    }

    /**
     * A theft that ends a sign-in is told to the person, once (ADR-062).
     *
     * SECURITY, so nobody can mute it. Once, because the family is revoked
     * by the first replay: a second replay ends nothing and says nothing.
     */
    public function testATheftThatEndsASignInIsToldToThePersonOnce(): void
    {
        $this->giveAHome();

        $first = $this->cookieFrom($this->signIn());
        $second = $this->cookieFrom($this->refresh($first));
        $this->refresh($second);
        $this->ageRotationOf($second, Sessions::RACE_WINDOW + 60);

        $replay = $this->refresh($first);
        $again = $this->refresh($first);

        self::assertSame(401, $replay->getStatusCode());
        self::assertSame(401, $again->getStatusCode());

        self::assertSame(1, $this->connection->fetchOne(
            "SELECT count(*) FROM notifications WHERE type = 'account.session_revoked' AND category = 'SECURITY' AND recipient_user_id = :u",
            ['u' => $this->userId],
        ));
    }

    /** A copy presented after a sign-out ends nothing, so it tells nobody anything. */
    public function testACopyPresentedAfterSigningOutSendsNoNotice(): void
    {
        $this->giveAHome();

        $cookie = $this->cookieFrom($this->signIn());
        $this->request('POST', '/api/v1/auth/sign-out', cookies: [RefreshCookie::NAME => $cookie]);

        self::assertSame(401, $this->refresh($cookie)->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'account.session_revoked'"));
    }

    /** A membership, so a notice has an organisation and a product to be raised under. */
    private function giveAHome(): void
    {
        $product = $this->connection->fetchOne("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $tenant = $this->connection->fetchOne("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        self::assertIsString($product);
        self::assertIsString($tenant);
        TestDatabase::assignProduct($this->connection, $tenant, $product);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:t, :p, :u)',
            ['t' => $tenant, 'p' => $product, 'u' => $this->userId],
        );
    }

    /**
     * A signed-out sign-in stays out, and a copy presented afterwards opens
     * nothing — without touching the person's other sign-ins.
     */
    public function testATokenEndedBySigningOutOpensNothingAndLeavesOtherSignInsAlone(): void
    {
        $signedOut = $this->cookieFrom($this->signIn());
        $elsewhere = $this->cookieFrom($this->signIn());

        self::assertSame(
            204,
            $this->request('POST', '/api/v1/auth/sign-out', cookies: [RefreshCookie::NAME => $signedOut])->getStatusCode(),
        );

        self::assertSame(401, $this->refresh($signedOut)->getStatusCode());
        self::assertSame(200, $this->refresh($elsewhere)->getStatusCode());
    }

    /**
     * Signing out ends the replacement the browser never received, too.
     *
     * A lost answer left a live token the browser does not hold; revoking
     * only the token presented would leave that one as a way back in.
     */
    public function testSigningOutEndsTheWholeSignIn(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $unreceived = $this->cookieFrom($this->refresh($first));

        $this->request('POST', '/api/v1/auth/sign-out', cookies: [RefreshCookie::NAME => $first]);

        self::assertSame(401, $this->refresh($unreceived)->getStatusCode());
        self::assertSame([], $this->liveTokens());
    }

    /**
     * A session ends at its maximum age, however busy (ADR-062).
     *
     * Rotation used to grant thirty more days on every refresh, so a session
     * in daily use — or a stolen one kept busy — never had to end.
     */
    public function testASignInEndsAtItsMaximumAgeWhateverItsActivity(): void
    {
        $cookie = $this->cookieFrom($this->signIn());

        $this->connection->executeStatement(
            'UPDATE auth_refresh_tokens SET family_started_at = now() - make_interval(secs => :age) WHERE user_id = :id',
            ['age' => Sessions::MAX_SESSION_AGE + 1, 'id' => $this->userId],
        );

        self::assertSame(401, $this->refresh($cookie)->getStatusCode());
    }

    /** And a replacement never outlives the sign-in it continues. */
    public function testAReplacementExpiresNoLaterThanItsSignIn(): void
    {
        $cookie = $this->cookieFrom($this->signIn());

        $this->connection->executeStatement(
            'UPDATE auth_refresh_tokens SET family_started_at = now() - make_interval(secs => :age) WHERE user_id = :id',
            ['age' => Sessions::MAX_SESSION_AGE - 3600, 'id' => $this->userId],
        );

        $renewed = $this->refresh($cookie);

        self::assertSame(200, $renewed->getStatusCode());
        self::assertMatchesRegularExpression('/Max-Age=(\d+)/', $renewed->getHeaderLine('Set-Cookie'));
        preg_match('/Max-Age=(\d+)/', $renewed->getHeaderLine('Set-Cookie'), $age);
        self::assertLessThanOrEqual(3600, (int) ($age[1] ?? PHP_INT_MAX));
    }

    /**
     * Rotating `AUTH_SIGNING_SECRET` does not strand a replacement computed
     * under the old one, while the old one is kept as the previous secret.
     */
    public function testASecretRotationStillHandsOverAReplacementMadeBeforeIt(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $lost = $this->cookieFrom($this->refresh($first));

        $this->override([
            RefreshRotation::class => new RefreshRotation('a-new-signing-secret-after-rotation', self::SECRET),
        ]);

        $again = $this->refresh($first);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($lost, $this->cookieFrom($again));
    }

    /**
     * Two cookies, one spent — and the account survives (2026-09-26).
     *
     * The day an operator sets `AUTH_COOKIE_DOMAIN`, the browser does not
     * replace the host-only cookie it already had: name, domain and path are
     * a cookie's identity, so it keeps both and sends both. The host-only one
     * holds a token that was legitimately rotated away.
     *
     * Read as theft, that revoked every session for the account — and again
     * on the next attempt, so the account never recovered. Nobody could sign
     * in for more than one request. This is that case, and it must pass.
     */
    public function testASpentCookieBesideALiveOneIsALeftoverAndNotTheft(): void
    {
        $spent = $this->cookieFrom($this->signIn());
        $live = $this->cookieFrom($this->request(
            'POST',
            '/api/v1/auth/refresh',
            cookies: [RefreshCookie::NAME => $spent],
        ));

        // What the browser actually sends while both cookies exist: one
        // header, the name twice. `getCookieParams()` cannot represent this,
        // which is why RefreshCookie reads the header itself.
        $both = $this->request('POST', '/api/v1/auth/refresh', [
            'Cookie' => sprintf('%s=%s; %s=%s', RefreshCookie::NAME, $spent, RefreshCookie::NAME, $live),
        ]);

        self::assertSame(200, $both->getStatusCode(), (string) $both->getBody());

        // And the account is intact: the token just issued still works, which
        // it would not if the spent one had triggered the family revocation.
        $after = $this->request(
            'POST',
            '/api/v1/auth/refresh',
            cookies: [RefreshCookie::NAME => $this->cookieFrom($both)],
        );

        self::assertSame(200, $after->getStatusCode());
    }

    /**
     * The order the browser chose must not decide the outcome.
     *
     * Whichever of the two comes first in the header, the live one is the one
     * used — the old code took whichever survived parsing, and that was the
     * difference between a working deployment and a locked account.
     */
    public function testTheOrderOfTheTwoCookiesDoesNotMatter(): void
    {
        $spent = $this->cookieFrom($this->signIn());
        $live = $this->cookieFrom($this->request(
            'POST',
            '/api/v1/auth/refresh',
            cookies: [RefreshCookie::NAME => $spent],
        ));

        $liveFirst = $this->request('POST', '/api/v1/auth/refresh', [
            'Cookie' => sprintf('%s=%s; %s=%s', RefreshCookie::NAME, $live, RefreshCookie::NAME, $spent),
        ]);

        self::assertSame(200, $liveFirst->getStatusCode(), (string) $liveFirst->getBody());
    }

    /**
     * Widening the cookie expires the host-only one it cannot replace.
     *
     * Without this the pair never ends: the browser carries the stale twin
     * until it expires on its own, and every request presents both. The
     * second `Set-Cookie` has no `Domain`, so it matches the host-only cookie
     * exactly, which is the only thing that can empty it.
     */
    public function testSettingAWidenedCookieAlsoExpiresTheHostOnlyTwin(): void
    {
        $response = RefreshCookie::set(
            new EmptyResponse(204),
            self::aRequest(),
            'a-token',
            3600,
            RefreshCookie::domainFrom('raillard.org'),
        );

        $headers = $response->getHeader('Set-Cookie');

        self::assertCount(2, $headers, 'One cookie to set, one twin to expire.');
        self::assertStringContainsString('Domain=raillard.org', $headers[0]);
        self::assertStringNotContainsString('Domain=', $headers[1]);
        self::assertStringContainsString('Max-Age=0', $headers[1]);
        self::assertStringContainsString('Path=' . RefreshCookie::PATH, $headers[1]);
    }

    /** And clearing does the same, or a sign-out leaves one of the two behind. */
    public function testClearingAWidenedCookieAlsoExpiresTheHostOnlyTwin(): void
    {
        $headers = RefreshCookie::clear(
            new EmptyResponse(204),
            self::aRequest(),
            RefreshCookie::domainFrom('raillard.org'),
        )->getHeader('Set-Cookie');

        self::assertCount(2, $headers);
        self::assertStringContainsString('Domain=raillard.org', $headers[0]);
        self::assertStringNotContainsString('Domain=', $headers[1]);
    }

    /** With no domain configured, nothing extra is written: there is no twin. */
    public function testAHostOnlyCookieWritesOneHeader(): void
    {
        $headers = RefreshCookie::set(new EmptyResponse(204), self::aRequest(), 'a-token', 3600)
            ->getHeader('Set-Cookie');

        self::assertCount(1, $headers);
    }

    /**
     * Signing out ends every token the browser presented, not the one that
     * survived parsing (2026-09-26). Ending one of two is not a sign-out.
     */
    public function testSigningOutRevokesEveryTokenPresented(): void
    {
        $first = $this->cookieFrom($this->signIn());
        $second = $this->cookieFrom($this->signIn());

        self::assertNotSame($first, $second);

        $out = $this->request('POST', '/api/v1/auth/sign-out', [
            'Cookie' => sprintf('%s=%s; %s=%s', RefreshCookie::NAME, $first, RefreshCookie::NAME, $second),
        ]);

        self::assertSame(204, $out->getStatusCode());

        foreach ([$first, $second] as $dead) {
            self::assertSame(
                401,
                $this->request('POST', '/api/v1/auth/refresh', cookies: [RefreshCookie::NAME => $dead])->getStatusCode(),
            );
        }
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
