<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * What a sign-up produced.
 *
 * Everything a caller needs to carry on without reading any of it back: the
 * session is issued from `userId` and `authSubject`, the purchase that
 * follows is placed against `tenantId` and `productId`, and the notification
 * telling them to confirm their address is raised against all four.
 */
final class RegisteredAccount
{
    public function __construct(
        public readonly string $userId,
        public readonly string $authSubject,
        public readonly string $email,
        public readonly string $tenantId,
        public readonly string $productId,
    ) {
    }
}
