<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use App\User\Domain\PlatformUser;
use App\User\Domain\UserRepository;

/**
 * User records without a database, for tests that exercise the context chain
 * rather than persistence.
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, PlatformUser> */
    private array $byId = [];

    /**
     * @param list<PlatformUser> $users
     */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->byId[$user->id] = $user;
        }
    }

    public function find(string $userId): ?PlatformUser
    {
        return $this->byId[$userId] ?? null;
    }

    public function findByEmail(string $email): array
    {
        $matches = [];

        foreach ($this->byId as $user) {
            if ($user->email !== null && strcasecmp($user->email, $email) === 0) {
                $matches[] = $user;
            }
        }

        return $matches;
    }

    public function updateDisplayName(string $userId, ?string $displayName): void
    {
        $user = $this->byId[$userId] ?? null;

        if ($user === null) {
            return;
        }

        $this->byId[$userId] = new PlatformUser($user->id, $user->authSubject, $user->email, $displayName);
    }
}
