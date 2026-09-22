<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AccessToken;
use App\Auth\Domain\TokenIssuer;
use App\Shared\Exceptions\NotConfiguredException;
use Firebase\JWT\JWT;
use SensitiveParameter;

/**
 * Mints EdDSA access tokens signed with a key derived from this deployment's
 * own secret (U12, then ADR-051 milestone E).
 *
 * **EdDSA now, HS256 before.** ADR-038 chose HS256 because issuer and verifier
 * were one process and a public key would have protected nothing. A product
 * beside the platform (ADR-051) is a second verifier, and under HS256 the only
 * way to let it verify is to hand it the mint. Ed25519 gives it the public
 * half through `GET /auth/jwks`, and the secret still never leaves `.env`:
 * the pair is derived from it ({@see LocalSigningKeys}), so nothing new is
 * generated, stored or rotated on the host's behalf.
 *
 * The token stays deliberately thin: subject, email and — when the request
 * named one — the product it was opened on, as a second audience. §10.6 says
 * tenant, product and roles are decisions this backend makes per request, not
 * facts a token asserts; the product in `aud` asserts nothing about what the
 * person may do, only which product's server may accept the token as its own.
 */
final class LocalJwtTokenIssuer implements TokenIssuer
{
    /** One hour. Long enough not to renew constantly, short enough that a leaked token expires. */
    public const DEFAULT_LIFETIME = 3600;

    /**
     * The shortest secret a key is derived from, in bytes.
     *
     * The bar HS256 set (`firebase/php-jwt` refuses to sign with less than the
     * hash it signs with), kept: a derivation from a short secret is a key
     * anybody can derive, and the answer an operator gets — 503, naming the
     * requirement — is the one the sign-in endpoint has given since U12.
     */
    public const MINIMUM_SECRET_BYTES = LocalSigningKeys::MINIMUM_SECRET_BYTES;

    private readonly LocalSigningKeys $keys;

    public function __construct(
        #[SensitiveParameter]
        string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $lifetimeSeconds = self::DEFAULT_LIFETIME,
    ) {
        $this->keys = new LocalSigningKeys($secret);
    }

    public function issue(string $authSubject, ?string $email, ?string $forProduct = null): AccessToken
    {
        if (!$this->keys->canSign()) {
            // Refused rather than signed with a key derived from nothing, or
            // from too little. Both become one answer, and it names the
            // requirement.
            throw new NotConfiguredException(
                'AUTHENTICATION_NOT_CONFIGURED',
                sprintf(
                    'This deployment cannot issue sessions: AUTH_SIGNING_SECRET must be at least %d characters.',
                    self::MINIMUM_SECRET_BYTES,
                ),
            );
        }

        $now = time();

        // The platform's name, and the product's beside it when there is one.
        // A list only when there is a second name in it: a verifier that reads
        // `aud` as a string keeps working for the platform's own shell, and
        // RFC 7519 allows either.
        $audiences = array_values(array_unique(array_filter(
            [$this->audience, (string) $forProduct],
            static fn (string $name): bool => $name !== '',
        )));

        $claims = [
            'iss' => $this->issuer,
            'aud' => count($audiences) === 1 ? $audiences[0] : $audiences,
            'sub' => $authSubject,
            'iat' => $now,
            'exp' => $now + $this->lifetimeSeconds,
        ];

        if ($email !== null && $email !== '') {
            $claims['email'] = $email;
        }

        return new AccessToken(
            JWT::encode($claims, $this->keys->currentSigningKey(), 'EdDSA', $this->keys->currentKeyId()),
            $this->lifetimeSeconds,
        );
    }
}
