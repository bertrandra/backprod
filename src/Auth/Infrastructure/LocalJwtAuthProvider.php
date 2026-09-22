<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Auth\Domain\AuthProvider;
use App\Shared\Exceptions\UnauthenticatedException;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Verifies the tokens `LocalJwtTokenIssuer` minted (U12, ADR-051 milestone E).
 *
 * The same shape as `SupabaseJwtAuthProvider` and for the same reasons: the
 * failure reason is logged rather than returned, because "expired" and "bad
 * signature" and "wrong issuer" are three different answers and telling them
 * apart is an oracle for probing tokens.
 *
 * **The algorithm is pinned by the keys.** `JWT::decode` is given the key set
 * keyed by `kid`, every key of which is EdDSA, and requires the header's
 * `alg` to match the key the `kid` names. A token arriving with `alg: none`,
 * with `alg: HS256` and a guessed secret, or with no `kid` at all has no key
 * to match and is refused before any claim is read — which also means the
 * HS256 tokens issued before this milestone die at the deploy, and every
 * client renews from its refresh cookie without noticing.
 *
 * During a rotation the previous secret's key verifies too; it signs nothing.
 */
final class LocalJwtAuthProvider implements AuthProvider
{
    private readonly LocalSigningKeys $keys;

    public function __construct(
        #[SensitiveParameter]
        string $secret,
        private readonly string $expectedIssuer,
        private readonly string $expectedAudience,
        private readonly LoggerInterface $logger,
        #[SensitiveParameter]
        string $previousSecret = '',
    ) {
        $this->keys = new LocalSigningKeys($secret, $previousSecret);
    }

    public function authenticate(string $credential): AuthenticatedIdentity
    {
        $keys = $this->keys->keys();

        if ($credential === '' || $keys === []) {
            // No secret means this deployment cannot have issued anything, so
            // there is nothing that could legitimately verify.
            throw new UnauthenticatedException();
        }

        try {
            $decoded = JWT::decode($credential, $keys);
        } catch (Throwable $e) {
            $this->logger->info('Token verification failed', ['reason' => $e::class]);

            throw new UnauthenticatedException();
        }

        /** @var array<string, mixed> $claims object properties are always string-keyed */
        $claims = get_object_vars($decoded);

        if (($claims['iss'] ?? null) !== $this->expectedIssuer) {
            $this->logger->info('Token verification failed', ['reason' => 'unexpected iss']);

            throw new UnauthenticatedException();
        }

        // `aud` is the platform's name, or a list that holds it beside the
        // product the session was opened on (RFC 7519 allows either). A
        // token naming a product and not the platform is somebody else's.
        $audience = $claims['aud'] ?? null;
        $accepted = is_array($audience)
            ? in_array($this->expectedAudience, $audience, true)
            : $audience === $this->expectedAudience;

        if (!$accepted) {
            $this->logger->info('Token verification failed', ['reason' => 'unexpected aud']);

            throw new UnauthenticatedException();
        }

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            $this->logger->info('Token verification failed', ['reason' => 'missing subject']);

            throw new UnauthenticatedException();
        }

        $email = $claims['email'] ?? null;

        $expiresAt = $claims['exp'] ?? null;

        return new AuthenticatedIdentity(
            $subject,
            is_string($email) && $email !== '' ? $email : null,
            is_int($expiresAt) ? $expiresAt : null,
        );
    }
}
