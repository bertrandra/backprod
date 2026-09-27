<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use App\Shared\Exceptions\NotConfiguredException;
use SensitiveParameter;

/**
 * What a refresh token is replaced by — always the same answer (ADR-062).
 *
 * A rotation used to replace a token with random bytes, so the server's idea
 * of "the current token" was whichever it had issued last, and the browser's
 * was whichever `Set-Cookie` it had stored last. Nothing kept the two equal:
 * a response lost to a closed lid, or two tabs whose answers arrived in the
 * other order, left the browser holding a token the server had already
 * replaced — and presenting it later read as theft and signed the person out.
 *
 * So the successor is **derived**: `HMAC(key, token)`. Everybody presenting
 * the same token is handed the same replacement, in whatever order and however
 * late, and asking twice cannot create two live tokens because there is only
 * one to create. The key is what stops a holder of one token computing the
 * rest of the chain.
 *
 * **The key is derived from `AUTH_SIGNING_SECRET`**, under a label of its own
 * so the two uses never share a key, rather than being one more secret an
 * operator has to set: a deployment that can sign access tokens can rotate
 * refresh tokens. The previous secret is kept beside it, as the verifier
 * keeps it, so a rotation of the secret does not strand a replacement
 * computed under the old one.
 */
final class RefreshRotation
{
    private const LABEL = 'backprod refresh-token rotation v1';

    /** @var list<string> current first */
    private readonly array $keys;

    public function __construct(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $previousSecret = '',
    ) {
        $keys = [];

        foreach ([$secret, $previousSecret] as $candidate) {
            // Below the signing minimum is no key at all: a successor derived
            // from a guessable key is a chain anybody can continue.
            if (strlen($candidate) >= LocalTokens::MINIMUM_SECRET_BYTES) {
                $keys[] = hash_hmac('sha256', self::LABEL, $candidate, true);
            }
        }

        $this->keys = $keys;
    }

    /** The replacement for a token, under the current key. */
    public function successorOf(#[SensitiveParameter] string $token): string
    {
        return $this->under($this->keys[0] ?? $this->refuse(), $token);
    }

    /**
     * The replacement whose hash the database recorded, whichever key made
     * it — or null when none did: a token rotated before this scheme, or
     * under a secret that is neither current nor previous. Either way the
     * replacement cannot be handed out again, which is a refusal and not a
     * theft.
     */
    public function successorMatching(#[SensitiveParameter] string $token, string $recordedHash): ?string
    {
        foreach ($this->keys as $key) {
            $candidate = $this->under($key, $token);

            if (hash_equals($recordedHash, hash('sha256', $candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function under(#[SensitiveParameter] string $key, #[SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, $key);
    }

    private function refuse(): never
    {
        throw new NotConfiguredException(
            'AUTHENTICATION_NOT_CONFIGURED',
            sprintf(
                'This deployment cannot issue sessions: AUTH_SIGNING_SECRET must be at least %d characters.',
                LocalTokens::MINIMUM_SECRET_BYTES,
            ),
        );
    }
}
