<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Auth\Domain\AuthProvider;
use App\Shared\Exceptions\UnauthenticatedException;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies Supabase RS256 access tokens locally (ADR-014).
 *
 * This class is the only place in the platform that knows tokens are JWTs or
 * that Supabase exists. Everything upstream depends on the AuthProvider port.
 */
final class SupabaseJwtAuthProvider implements AuthProvider
{
    public function __construct(
        private readonly SigningKeySource $keys,
        private readonly string $expectedIssuer,
        private readonly string $expectedAudience,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authenticate(string $credential): AuthenticatedIdentity
    {
        if ($credential === '') {
            throw new UnauthenticatedException();
        }

        try {
            // Verifies signature, exp and nbf, and rejects the "none" algorithm
            // by requiring a key whose algorithm matches the header.
            $decoded = JWT::decode($credential, $this->keys->keys());
        } catch (Throwable $e) {
            // The specific reason is an oracle for token probing, so it is
            // logged rather than returned (ADR-014).
            $this->logger->info('Token verification failed', ['reason' => $e::class]);

            throw new UnauthenticatedException();
        }

        $claims = get_object_vars($decoded);

        $this->assertClaim($claims, 'iss', $this->expectedIssuer);
        $this->assertAudience($claims);

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            $this->logger->info('Token verification failed', ['reason' => 'missing subject']);

            throw new UnauthenticatedException();
        }

        $email = $claims['email'] ?? null;

        return new AuthenticatedIdentity(
            $subject,
            is_string($email) && $email !== '' ? $email : null,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaim(array $claims, string $name, string $expected): void
    {
        if (($claims[$name] ?? null) !== $expected) {
            $this->logger->info('Token verification failed', ['reason' => "unexpected {$name}"]);

            throw new UnauthenticatedException();
        }
    }

    /**
     * `aud` may be a single value or a list, per RFC 7519.
     *
     * @param array<string, mixed> $claims
     */
    private function assertAudience(array $claims): void
    {
        $audience = $claims['aud'] ?? null;

        $accepted = is_array($audience)
            ? in_array($this->expectedAudience, $audience, true)
            : $audience === $this->expectedAudience;

        if (!$accepted) {
            $this->logger->info('Token verification failed', ['reason' => 'unexpected aud']);

            throw new UnauthenticatedException();
        }
    }
}
