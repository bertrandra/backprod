<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\PublicKeys;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Auth\Infrastructure\LocalSigningKeys;
use App\Shared\Logging\ErrorLogLogger;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * A product beside the platform verifies a bearer with nothing it could
 * sign with (ADR-051 milestone E), through the real endpoints.
 *
 * The product's side is played here with the same JWT library the platform
 * uses, fed only what `GET /auth/jwks` answered: if that verifies the token
 * `POST /auth/token` issued, a product developer with any JWK-aware library
 * can do the same. Then the two things a rotation has to keep true — the
 * old token still verifies for its hour, and the set says so.
 */
#[CoversNothing]
final class LocalTokenVerificationTest extends DatabaseApiTestCase
{
    private const SECRET = 'a-test-signing-secret-nobody-deploys';
    private const NEXT_SECRET = 'the-secret-after-the-rotation-nobody-deploys';
    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->executeStatement("INSERT INTO products (code, name, active) VALUES ('plan', 'Plan', true), ('orbit', 'Orbit', false)");
        $userId = $this->connection->fetchOne("INSERT INTO users (auth_subject, email, display_name) VALUES ('pending', 'ada@acme.test', 'Ada') RETURNING id");
        self::assertIsString($userId);
        $this->connection->executeStatement("UPDATE users SET auth_subject = 'local:' || id WHERE id = :id", ['id' => $userId]);
        $this->connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            ['id' => $userId, 'email' => 'ada@acme.test', 'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])],
        );

        $this->configure(self::SECRET);
    }

    public function testAProductVerifiesTheTokenWithTheKeySetAndFindsItselfInTheAudience(): void
    {
        $signedIn = $this->signIn('plan');
        self::assertSame(200, $signedIn->getStatusCode());
        $token = $this->decode($signedIn)['access_token'] ?? null;
        self::assertIsString($token);

        // The header says how it is signed and by which key, and nothing else.
        $header = $this->part($token, 0);
        self::assertSame('EdDSA', $header['alg'] ?? null);
        self::assertIsString($header['kid'] ?? null);

        // The key set is public, cacheable, and names that key.
        $published = $this->request('GET', '/api/v1/auth/jwks');
        self::assertSame(200, $published->getStatusCode());
        self::assertSame('public, max-age=300', $published->getHeaderLine('Cache-Control'));
        $jwks = $this->decode($published);
        $keys = $jwks['keys'] ?? null;
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $first = $keys[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $header['kid'], 'use' => 'sig', 'alg' => 'EdDSA'], array_diff_key($first, ['x' => 1]));
        self::assertStringNotContainsString(self::SECRET, json_encode($jwks) ?: '');

        // The product's side: the published set and a JWT library, nothing
        // from this deployment's .env.
        $claims = get_object_vars(JWT::decode($token, JWK::parseKeySet($jwks)));
        self::assertSame(LocalTokens::DEFAULT_ISSUER, $claims['iss'] ?? null);
        self::assertSame([LocalTokens::DEFAULT_AUDIENCE, 'plan'], $claims['aud'] ?? null);
        self::assertSame('ada@acme.test', $claims['email'] ?? null);
        self::assertIsString($claims['sub'] ?? null);

        // And the platform still takes it as its own — on an identity-only
        // route: Ada holds no membership, and that is a different refusal.
        self::assertSame(200, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
    }

    public function testATokenNamesTheProductOnlyWhenTheRequestNamedALiveOne(): void
    {
        $alone = $this->tokenOf($this->signIn(null));
        self::assertSame(LocalTokens::DEFAULT_AUDIENCE, $this->part($alone, 1)['aud'] ?? null);

        // A retired product, or one this platform does not host, names nothing:
        // the token is the platform's alone rather than a refusal.
        self::assertSame(LocalTokens::DEFAULT_AUDIENCE, $this->part($this->tokenOf($this->signIn('orbit')), 1)['aud'] ?? null);
        self::assertSame(LocalTokens::DEFAULT_AUDIENCE, $this->part($this->tokenOf($this->signIn('nothing-here')), 1)['aud'] ?? null);

        // Renewed on the product's page, the token names it: single sign-on
        // from the cookie yields one the product's server can verify as its own.
        $cookie = $this->refreshCookieOf($this->signIn(null));
        $renewed = $this->request('POST', '/api/v1/auth/refresh', ['X-Product' => 'plan'], null, [], ['backprod_refresh' => $cookie]);
        self::assertSame(200, $renewed->getStatusCode());
        self::assertSame([LocalTokens::DEFAULT_AUDIENCE, 'plan'], $this->part($this->tokenOf($renewed), 1)['aud'] ?? null);
    }

    public function testARotationKeepsTheOldTokenVerifyingForItsHourAndSaysSoInTheSet(): void
    {
        $before = $this->tokenOf($this->signIn('plan'));
        $oldKid = $this->part($before, 0)['kid'] ?? null;

        // The operator moves the secret to PREVIOUS and sets a new one.
        $this->configure(self::NEXT_SECRET, self::SECRET);

        $after = $this->tokenOf($this->signIn('plan'));
        $newKid = $this->part($after, 0)['kid'] ?? null;
        self::assertNotSame($oldKid, $newKid);

        // Both verify on the platform, and both keys are published.
        self::assertSame(200, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $before])->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $after])->getStatusCode());
        $jwks = $this->decode($this->request('GET', '/api/v1/auth/jwks'));
        $published = $jwks['keys'] ?? null;
        self::assertIsArray($published);
        $kids = array_map(static fn (mixed $key): mixed => is_array($key) ? ($key['kid'] ?? null) : null, $published);
        self::assertSame([$newKid, $oldKid], $kids);
        JWT::decode($before, JWK::parseKeySet($jwks));
        JWT::decode($after, JWK::parseKeySet($jwks));

        // The hour passes, PREVIOUS is removed: the old token is a stranger's.
        $this->configure(self::NEXT_SECRET);
        self::assertSame(401, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $before])->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $after])->getStatusCode());
        $remaining = $this->decode($this->request('GET', '/api/v1/auth/jwks'))['keys'] ?? null;
        self::assertIsArray($remaining);
        self::assertCount(1, $remaining);
    }

    public function testATokenSignedWithTheSecretItselfIsRefused(): void
    {
        // What a product that was handed the secret and signed HS256 with it
        // would produce, and what the mint used to produce: no key matches.
        $forged = JWT::encode(['iss' => LocalTokens::DEFAULT_ISSUER, 'aud' => LocalTokens::DEFAULT_AUDIENCE, 'sub' => 'local:x', 'exp' => time() + 60], self::SECRET, 'HS256');

        self::assertSame(401, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $forged])->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------

    private function configure(string $secret, string $previous = ''): void
    {
        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer($secret, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE),
            AuthProvider::class => new LocalJwtAuthProvider($secret, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE, new ErrorLogLogger(), $previous),
            PublicKeys::class => new LocalSigningKeys($secret, $previous),
        ]);
    }

    private function signIn(?string $product): ResponseInterface
    {
        $headers = $product === null ? [] : ['X-Product' => $product];

        return $this->request('POST', '/api/v1/auth/token', $headers, $this->json(['email' => 'ada@acme.test', 'password' => self::PASSWORD]));
    }

    private function tokenOf(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode());
        $token = $this->decode($response)['access_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function refreshCookieOf(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^backprod_refresh=([^;]+)/', $cookie, $found) === 1) {
                return $found[1];
            }
        }

        self::fail('No refresh cookie was set.');
    }

    /**
     * One segment of a token, decoded without verifying — this is a test
     * reading what was written, not a verifier.
     *
     * @return array<string, mixed>
     */
    private function part(string $token, int $index): array
    {
        $segment = explode('.', $token)[$index] ?? '';
        $decoded = json_decode((string) base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
