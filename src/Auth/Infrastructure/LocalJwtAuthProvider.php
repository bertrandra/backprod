<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Auth\Domain\AuthProvider;
use App\Shared\Exceptions\UnauthenticatedException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Verifies the tokens `LocalJwtTokenIssuer` minted (U12).
 *
 * The same shape as `SupabaseJwtAuthProvider` and for the same reasons: the
 * failure reason is logged rather than returned, because "expired" and "bad
 * signature" and "wrong issuer" are three different answers and telling them
 * apart is an oracle for probing tokens.
 *
 * **The algorithm is pinned to HS256 by passing exactly one key.** `JWT::decode`
 * requires the header's `alg` to match the key it is given, so a token arriving
 * with `alg: none` — or with `alg: RS256` and the HMAC secret as a forged public
 * key, which is the classic confusion attack — has no key to match and is
 * refused before any claim is read.
 */
final class LocalJwtAuthProvider implements AuthProvider
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $secret,
        private readonly string $expectedIssuer,
        private readonly string $expectedAudience,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authenticate(string $credential): AuthenticatedIdentity
    {
        if ($credential === '' || $this->secret === '') {
            // No secret means this deployment cannot have issued anything, so
            // there is nothing that could legitimately verify.
            throw new UnauthenticatedException();
        }

        try {
            $decoded = JWT::decode($credential, new Key($this->secret, 'HS256'));
        } catch (Throwable $e) {
            $this->logger->info('Token verification failed', ['reason' => $e::class]);

            throw new UnauthenticatedException();
        }

        /** @var array<string, mixed> $claims object properties are always string-keyed */
        $claims = get_object_vars($decoded);

        foreach (['iss' => $this->expectedIssuer, 'aud' => $this->expectedAudience] as $name => $expected) {
            if (($claims[$name] ?? null) !== $expected) {
                $this->logger->info('Token verification failed', ['reason' => "unexpected {$name}"]);

                throw new UnauthenticatedException();
            }
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
