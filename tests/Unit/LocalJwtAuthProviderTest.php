<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Domain\LocalTokens;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Shared\Logging\ErrorLogLogger;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * The forgeries a token verifier has to refuse (U12).
 *
 * Every case here is a token somebody constructs on purpose, which is why none of
 * them is reachable through `SignInTest`: that test drives the real path, and the
 * real path never produces a token with the wrong algorithm in its header.
 */
final class LocalJwtAuthProviderTest extends TestCase
{
    private const SECRET = 'a-test-signing-secret-nobody-deploys';

    private function provider(string $secret = self::SECRET): LocalJwtAuthProvider
    {
        return new LocalJwtAuthProvider(
            $secret,
            LocalTokens::DEFAULT_ISSUER,
            LocalTokens::DEFAULT_AUDIENCE,
            new ErrorLogLogger(),
        );
    }

    private function issuer(string $secret = self::SECRET): LocalJwtTokenIssuer
    {
        return new LocalJwtTokenIssuer($secret, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE);
    }

    public function testItAcceptsATokenItIssued(): void
    {
        $token = $this->issuer()->issue('local:abc', 'ada@acme.test');

        $identity = $this->provider()->authenticate($token->token);

        self::assertSame('local:abc', $identity->userId);
        self::assertSame('ada@acme.test', $identity->email);
    }

    public function testItRefusesATokenSignedWithAnotherSecret(): void
    {
        // The situation two deployments sharing nothing should be in — and the
        // reason a copied `.env` does not make one deployment's tokens work on
        // another.
        //
        // 32 characters at least. The first version of this test used a 29-character
        // secret and got `DomainException: Provided key is too short` out of the
        // signing library — which is how `MINIMUM_SECRET_BYTES` came to be checked
        // where an operator can see the answer.
        $token = $this->issuer('a-different-deployments-signing-secret')->issue('local:abc', null);

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($token->token);
    }

    public function testItRefusesTheNoneAlgorithm(): void
    {
        // The oldest JWT attack: strip the signature and say there is no algorithm.
        // `JWT::decode` is given exactly one key, whose algorithm must match the
        // header, so there is nothing for `none` to match.
        $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        $claims = rtrim(strtr(base64_encode(json_encode([
            'iss' => LocalTokens::DEFAULT_ISSUER,
            'aud' => LocalTokens::DEFAULT_AUDIENCE,
            'sub' => 'local:abc',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($header . '.' . $claims . '.');
    }

    public function testItRefusesATokenWhoseIssuerIsSomebodyElse(): void
    {
        // A token minted by another system that happens to share this secret. The
        // signature would verify; the claim is what refuses it.
        $token = JWT::encode(
            [
                'iss' => 'some-other-platform',
                'aud' => LocalTokens::DEFAULT_AUDIENCE,
                'sub' => 'local:abc',
                'exp' => time() + 3600,
            ],
            self::SECRET,
            'HS256',
        );

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($token);
    }

    public function testItRefusesATokenMeantForAnotherAudience(): void
    {
        $token = JWT::encode(
            [
                'iss' => LocalTokens::DEFAULT_ISSUER,
                'aud' => 'some-other-api',
                'sub' => 'local:abc',
                'exp' => time() + 3600,
            ],
            self::SECRET,
            'HS256',
        );

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($token);
    }

    public function testItRefusesAnExpiredToken(): void
    {
        $token = JWT::encode(
            [
                'iss' => LocalTokens::DEFAULT_ISSUER,
                'aud' => LocalTokens::DEFAULT_AUDIENCE,
                'sub' => 'local:abc',
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ],
            self::SECRET,
            'HS256',
        );

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($token);
    }

    public function testItRefusesATokenWithNoSubject(): void
    {
        // Signed, in date, addressed correctly, and names nobody. Without this
        // check the identity would be an empty string, which `PostgresUserDirectory`
        // would then happily upsert as a user.
        $token = JWT::encode(
            [
                'iss' => LocalTokens::DEFAULT_ISSUER,
                'aud' => LocalTokens::DEFAULT_AUDIENCE,
                'exp' => time() + 3600,
            ],
            self::SECRET,
            'HS256',
        );

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->authenticate($token);
    }

    public function testASecretTooShortToSignWithIsRefusedWithAnAnswerAnOperatorCanActOn(): void
    {
        $this->expectExceptionMessage('at least 32 characters');
        $this->issuer('too short to be a secret')->issue('local:abc', null);
    }

    public function testADeploymentWithNoSecretAuthenticatesNobody(): void
    {
        // It cannot have issued anything, so there is nothing that could
        // legitimately verify — and an empty HMAC key is one anybody can compute.
        $token = $this->issuer()->issue('local:abc', null);

        $this->expectException(UnauthenticatedException::class);
        $this->provider('')->authenticate($token->token);
    }
}
