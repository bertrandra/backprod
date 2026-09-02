<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\User\Domain\PlatformUser;
use App\User\Domain\UserRepository;

/**
 * The caller's own account.
 *
 * Thin, but it exists so controllers orchestrate nothing themselves: when
 * profile changes grow an audit trail or a privacy export (§26.1), they
 * attach here rather than to an HTTP handler.
 */
final class Profile
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function of(string $userId): ?PlatformUser
    {
        return $this->users->find($userId);
    }

    public function rename(string $userId, ?string $displayName): ?PlatformUser
    {
        $this->users->updateDisplayName($userId, $displayName);

        return $this->users->find($userId);
    }
}
