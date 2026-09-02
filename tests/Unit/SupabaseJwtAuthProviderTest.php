<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Infrastructure\NullSigningKeySource;
use App\Auth\Infrastructure\StaticSigningKeySource;
use App\Auth\Infrastructure\SupabaseJwtAuthProvider;
use App\Shared\Exceptions\UnauthenticatedException;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Verifies the adapter against genuine RS256 tokens, signed with a keypair
 * generated here. No network and no Supabase project required, so the
 * signature path is actually exercised rather than mocked away.
 */
#[CoversClass(SupabaseJwtAuthProvider::class)]
final class SupabaseJwtAuthProviderTest extends TestCase
{
    private const ISSUER = 'https://project.supabase.test/auth/v1';
    private const AUDIENCE = 'authenticated';
    private const KEY_ID = 'test-key';

    private OpenSSLAsymmetricKey $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not generate a test keypair.');
        }

        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !is_array($details['rsa'] ?? null)) {
            throw new RuntimeException('Could not read the generated key.');
        }

        $modulus = $details['rsa']['n'] ?? null;
        $exponent = $details['rsa']['e'] ?? null;

        if (!is_string($modulus) || !is_string($exponent)) {
            throw new RuntimeException('Generated key is missing its RSA parameters.');
        }

        $this->privateKey = $key;
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url($modulus),
            'e' => self::base64Url($exponent),
        ]]];
    }

    public function testValidTokenYieldsTheSubjectAsUserId(): void
    {
        $identity = $this->provider()->authenticate($this->token());

        self::assertSame('user-123', $identity->userId);
        self::assertSame('person@example.test', $identity->email);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($this->token(['exp' => time() - 60]));
    }

    public function testTokenNotYetValidIsRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($this->token(['nbf' => time() + 3600]));
    }

    public function testTokenFromAnotherIssuerIsRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($this->token(['iss' => 'https://attacker.test/auth/v1']));
    }

    public function testTokenForAnotherAudienceIsRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($this->token(['aud' => 'some-other-service']));
    }

    public function testAudienceMayBeAList(): void
    {
        $identity = $this->provider()->authenticate(
            $this->token(['aud' => ['something-else', self::AUDIENCE]]),
        );

        self::assertSame('user-123', $identity->userId);
    }

    public function testTokenWithoutSubjectIsRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($this->token(['sub' => null]));
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = $this->token();
        $tampered = substr($token, 0, -3) . 'AAA';

        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate($tampered);
    }

    public function testGarbageAndEmptyCredentialsAreRejected(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->provider()->authenticate('not-a-token');
    }

    /**
     * An unconfigured deployment must authenticate nobody rather than
     * everybody (NullSigningKeySource).
     */
    public function testNoConfiguredKeysRejectsAnOtherwiseValidToken(): void
    {
        $provider = new SupabaseJwtAuthProvider(
            new NullSigningKeySource(),
            self::ISSUER,
            self::AUDIENCE,
            new NullLogger(),
        );

        $this->expectException(UnauthenticatedException::class);

        $provider->authenticate($this->token());
    }

    private function provider(): SupabaseJwtAuthProvider
    {
        return new SupabaseJwtAuthProvider(
            new StaticSigningKeySource($this->jwks),
            self::ISSUER,
            self::AUDIENCE,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'user-123',
            'email' => 'person@example.test',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ];

        foreach ($overrides as $claim => $value) {
            if ($value === null) {
                unset($claims[$claim]);

                continue;
            }

            $claims[$claim] = $value;
        }

        return JWT::encode($claims, $this->privateKey, 'RS256', self::KEY_ID);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
