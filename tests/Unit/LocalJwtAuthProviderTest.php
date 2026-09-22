<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Domain\LocalTokens;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Auth\Infrastructure\LocalSigningKeys;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Shared\Logging\ErrorLogLogger;
use Firebase\JWT\JWK;
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

    public function testItSignsEdDsaUnderAKeyIdThePublishedSetNames(): void
    {
        // ADR-051 milestone E: a verifier with the public set and no secret.
        $token = $this->issuer()->issue('local:abc', null, 'plan');
        $keys = new LocalSigningKeys(self::SECRET);

        [$header] = explode('.', $token->token);
        $decodedHeader = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true);
        self::assertIsArray($decodedHeader);
        self::assertSame('EdDSA', $decodedHeader['alg'] ?? null);
        self::assertSame($keys->currentKeyId(), $decodedHeader['kid'] ?? null);

        $claims = get_object_vars(JWT::decode($token->token, JWK::parseKeySet($keys->jwks())));
        self::assertSame([LocalTokens::DEFAULT_AUDIENCE, 'plan'], $claims['aud'] ?? null);

        // The platform takes a token that also names a product as its own.
        self::assertSame('local:abc', $this->provider()->authenticate($token->token)->userId);
    }

    public function testThePreviousSecretVerifiesButDoesNotSign(): void
    {
        $old = $this->issuer()->issue('local:abc', null);
        $rotated = new LocalJwtAuthProvider('a-different-deployments-signing-secret', LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE, new ErrorLogLogger(), self::SECRET);

        self::assertSame('local:abc', $rotated->authenticate($old->token)->userId);

        $set = (new LocalSigningKeys('a-different-deployments-signing-secret', self::SECRET))->jwks();
        self::assertCount(2, $set['keys']);
        self::assertSame((new LocalSigningKeys('a-different-deployments-signing-secret'))->currentKeyId(), $set['keys'][0]['kid']);
        self::assertSame((new LocalSigningKeys(self::SECRET))->currentKeyId(), $set['keys'][1]['kid']);
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
        // `JWT::decode` is given keys by `kid`, every one EdDSA, and the header
        // must name one whose algorithm matches, so there is nothing for `none`
        // to match. The HS256 forgeries below are refused the same way: they
        // carry no `kid`, and the secret is not a key any more.
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
