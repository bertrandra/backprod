<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * A refresh token as the database knows it: by its hash, and whether it is spent.
 *
 * `revoked` and `expired` are kept apart because they mean different things to
 * the service. An expired token is somebody who left the tab open too long. A
 * *revoked* one being presented is somebody using a credential that was already
 * exchanged — either a replay or a theft — and the answer to that is to revoke
 * the whole family rather than to shrug and refuse one request. Unless it was
 * exchanged seconds ago, which is one browser's two tabs racing each other and
 * not two holders: `Sessions::REFRESH_GRACE`, and the chain says which.
 */
final class StoredRefreshToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $authSubject,
        public readonly ?string $email,
        public readonly bool $revoked,
        public readonly bool $expired,
    ) {
    }

    public function usable(): bool
    {
        return !$this->revoked && !$this->expired;
    }
}
