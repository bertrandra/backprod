<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use App\User\Domain\PlatformUser;
use App\User\Domain\UserRepository;
use Doctrine\DBAL\Connection;

final class PostgresUserRepository implements UserRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $userId): ?PlatformUser
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, auth_subject, email, display_name FROM users WHERE id = :id',
            ['id' => $userId],
        );

        return $row === false ? null : $this->toUser($row);
    }

    public function findByEmail(string $email): array
    {
        // Case-insensitive: people type their own address with whatever
        // capitalisation they please, and providers treat it as equivalent.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, auth_subject, email, display_name
                FROM users
                WHERE lower(email) = lower(:email)
                ORDER BY id
                SQL,
            ['email' => $email],
        );

        $users = [];

        foreach ($rows as $row) {
            $user = $this->toUser($row);

            if ($user !== null) {
                $users[] = $user;
            }
        }

        return $users;
    }

    public function updateDisplayName(string $userId, ?string $displayName): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET display_name = :displayName, updated_at = now() WHERE id = :id',
            ['displayName' => $displayName, 'id' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toUser(array $row): ?PlatformUser
    {
        $id = $row['id'] ?? null;
        $subject = $row['auth_subject'] ?? null;

        if (!is_string($id) || !is_string($subject)) {
            return null;
        }

        $email = $row['email'] ?? null;
        $displayName = $row['display_name'] ?? null;

        return new PlatformUser(
            $id,
            $subject,
            is_string($email) ? $email : null,
            is_string($displayName) ? $displayName : null,
        );
    }
}
