<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Auth\Domain\AuthProvider;
use App\Shared\Exceptions\UnauthenticatedException;

/**
 * Stands in for the identity provider so pipeline tests exercise the context
 * chain rather than JWT mechanics. The real adapter is covered separately by
 * SupabaseJwtAuthProviderTest against genuine RS256 tokens.
 */
final class FakeAuthProvider implements AuthProvider
{
    /**
     * @param array<string, string> $tokenToUserId
     */
    public function __construct(private readonly array $tokenToUserId)
    {
    }

    public function authenticate(string $credential): AuthenticatedIdentity
    {
        $userId = $this->tokenToUserId[$credential] ?? null;

        if ($userId === null) {
            throw new UnauthenticatedException();
        }

        return new AuthenticatedIdentity($userId, $userId . '@example.test');
    }
}
