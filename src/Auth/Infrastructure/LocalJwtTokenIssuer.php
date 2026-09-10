<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AccessToken;
use App\Auth\Domain\TokenIssuer;
use App\Shared\Exceptions\NotConfiguredException;
use Firebase\JWT\JWT;
use SensitiveParameter;

/**
 * Mints HS256 access tokens signed with this deployment's own secret (U12).
 *
 * **HS256 rather than RS256, deliberately.** An asymmetric key pair exists so
 * that a *verifier* need not be trusted with the ability to sign — which matters
 * when the issuer and the verifier are different systems, as they were when
 * Supabase issued and this platform verified. Here they are the same process, so
 * a public key would protect nothing and would cost key generation, key storage
 * and a rotation story on a host whose secret management is a `.env` file.
 *
 * The token stays deliberately thin: subject and email, nothing else. §10.6 says
 * tenant, product and roles are decisions this backend makes per request, not
 * facts a token asserts — a token carrying a tenant would be a way to choose one.
 */
final class LocalJwtTokenIssuer implements TokenIssuer
{
    /** One hour. Long enough not to renew constantly, short enough that a leaked token expires. */
    public const DEFAULT_LIFETIME = 3600;

    /**
     * The shortest secret HS256 will accept, in bytes.
     *
     * Not a policy invented here: `firebase/php-jwt` refuses to sign with a key
     * shorter than the hash it is signing with, because a 16-byte key gives 128
     * bits of security to a 256-bit MAC. Found by writing a test with a 29-character
     * secret, which threw `DomainException: Provided key is too short` — and would
     * have reached an operator as a 500 on the sign-in endpoint rather than as
     * anything about their configuration.
     */
    public const MINIMUM_SECRET_BYTES = 32;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $lifetimeSeconds = self::DEFAULT_LIFETIME,
    ) {
    }

    public function issue(string $authSubject, ?string $email): AccessToken
    {
        if (strlen($this->secret) < self::MINIMUM_SECRET_BYTES) {
            /**
             * Refused rather than signed with nothing, or with too little.
             *
             * `JWT::encode` with an *empty* key produces a perfectly well-formed
             * token whose HMAC anybody can recompute — a deployment signing people
             * in with forgeable credentials, which is worse than not working because
             * it looks like working. With a *short* key it throws a
             * `DomainException` from inside the library, which reaches the operator
             * as a 500 that says nothing about their configuration.
             *
             * Both become one answer, and it names the requirement.
             */
            throw new NotConfiguredException(
                'AUTHENTICATION_NOT_CONFIGURED',
                sprintf(
                    'This deployment cannot issue sessions: AUTH_SIGNING_SECRET must be at least %d characters.',
                    self::MINIMUM_SECRET_BYTES,
                ),
            );
        }

        $now = time();

        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $authSubject,
            'iat' => $now,
            'exp' => $now + $this->lifetimeSeconds,
        ];

        if ($email !== null && $email !== '') {
            $claims['email'] = $email;
        }

        return new AccessToken(JWT::encode($claims, $this->secret, 'HS256'), $this->lifetimeSeconds);
    }
}
