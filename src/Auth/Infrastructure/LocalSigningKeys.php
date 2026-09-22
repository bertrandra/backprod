<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\PublicKeys;
use Firebase\JWT\Key;
use SensitiveParameter;

/**
 * The Ed25519 key pairs this deployment signs sessions with, derived from
 * its secret (ADR-051 milestone E).
 *
 * **Derived, not stored.** The pair is a pure function of
 * `AUTH_SIGNING_SECRET`: the same secret always yields the same key, so
 * there is no key file, no table of keys and no sealing story on a host
 * whose secret management is a `.env` file — and possession of the secret
 * is the ability to sign, exactly as it was under HS256. What changes is
 * what a *verifier* needs: the public half, which is derived here too and
 * served as a JWK set, so a product beside the platform verifies a bearer
 * with nothing it could mint one with.
 *
 * **Rotation is two secrets for an hour.** `AUTH_SIGNING_SECRET_PREVIOUS`
 * keeps the old pair verifying — and in the JWK set — while tokens signed
 * before the change run out; the current secret alone signs. After one
 * access-token lifetime the previous value is removed and the old key is
 * gone everywhere at once.
 *
 * The `kid` is a fingerprint of the public key, so a verifier that caches
 * the set can tell a new key from a stale cache without parsing anything.
 */
final class LocalSigningKeys implements PublicKeys, SigningKeySource
{
    /** The shortest secret worth deriving from, in bytes: the same bar HS256 set. */
    public const MINIMUM_SECRET_BYTES = 32;

    /** Domain separation for the derivation, so this secret used elsewhere yields something else. */
    private const CONTEXT = 'backprod.session-signing.v1';

    /** @var list<array{kid: string, public: string, secret: string}> current first */
    private readonly array $pairs;

    public function __construct(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $previousSecret = '',
    ) {
        $pairs = [];

        foreach ([$secret, $previousSecret] as $candidate) {
            if (strlen($candidate) < self::MINIMUM_SECRET_BYTES) {
                continue;
            }

            $seed = sodium_crypto_generichash($candidate, self::CONTEXT, SODIUM_CRYPTO_SIGN_SEEDBYTES);
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $public = sodium_crypto_sign_publickey($pair);

            $pairs[] = [
                // Sixteen hex characters of a BLAKE2b fingerprint: libsodium
                // hashes to sixteen bytes at least, and a `kid` needs to be
                // distinct, not long.
                'kid' => substr(bin2hex(sodium_crypto_generichash($public, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN)), 0, 16),
                'public' => $public,
                'secret' => sodium_crypto_sign_secretkey($pair),
            ];
        }

        $this->pairs = $pairs;
    }

    /** Whether there is a secret to sign with at all. */
    public function canSign(): bool
    {
        return $this->pairs !== [];
    }

    /** The `kid` the current key signs under. */
    public function currentKeyId(): string
    {
        return $this->pairs[0]['kid'] ?? '';
    }

    /**
     * The current signing key, in the form the JWT library takes for EdDSA:
     * the 64-byte libsodium secret key, base64.
     */
    public function currentSigningKey(): string
    {
        return base64_encode($this->pairs[0]['secret'] ?? '');
    }

    public function keys(): array
    {
        $keys = [];

        foreach ($this->pairs as $pair) {
            $keys[$pair['kid']] = new Key(base64_encode($pair['public']), 'EdDSA');
        }

        return $keys;
    }

    public function jwks(): array
    {
        $keys = [];

        foreach ($this->pairs as $pair) {
            $keys[] = [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'kid' => $pair['kid'],
                'x' => rtrim(strtr(base64_encode($pair['public']), '+/', '-_'), '='),
                'use' => 'sig',
                'alg' => 'EdDSA',
            ];
        }

        return ['keys' => $keys];
    }
}
