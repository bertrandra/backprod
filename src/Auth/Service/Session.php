<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\AccessToken;
use SensitiveParameter;

/**
 * What a successful sign-in or renewal produces.
 *
 * Two credentials with deliberately different lifetimes and deliberately
 * different homes: the access token goes in the response body for the browser to
 * hold in memory, and the refresh token goes in an `HttpOnly` cookie that no
 * script can read. That asymmetry is the whole security argument — a stolen
 * access token expires in an hour, and the long-lived one is not reachable by
 * injected JavaScript at all.
 */
final class Session
{
    public function __construct(
        public readonly AccessToken $accessToken,
        #[SensitiveParameter]
        public readonly string $refreshToken,
        public readonly int $refreshLifetimeSeconds,
    ) {
    }
}
