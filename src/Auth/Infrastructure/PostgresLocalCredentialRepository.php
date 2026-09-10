<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\LocalCredential;
use App\Auth\Domain\LocalCredentialRepository;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

final class PostgresLocalCredentialRepository implements LocalCredentialRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByEmail(string $email): ?LocalCredential
    {
        // Joined rather than two queries, because the caller needs the *subject*
        // and the id together and fetching them separately leaves room for them
        // to disagree.
        //
        // `erased_at IS NULL` is a security condition, not a tidy-up: §15 keeps
        // the users row for legal retention after a person is erased, and an
        // erased person who can still sign in has not been erased.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT c.user_id, u.auth_subject, c.email, c.password_hash
                FROM local_credentials c
                JOIN users u ON u.id = c.user_id
                WHERE lower(c.email) = lower(:email)
                  AND u.erased_at IS NULL
                SQL,
            ['email' => $email],
        );

        if ($row === false) {
            return null;
        }

        return new LocalCredential(
            Row::string($row, 'user_id'),
            Row::string($row, 'auth_subject'),
            Row::string($row, 'email'),
            Row::string($row, 'password_hash'),
        );
    }

    public function save(string $userId, string $email, #[SensitiveParameter] string $passwordHash): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO local_credentials (user_id, email, password_hash)
                VALUES (:user_id, :email, :password_hash)
                ON CONFLICT (user_id) DO UPDATE
                    SET email = excluded.email,
                        password_hash = excluded.password_hash,
                        updated_at = now()
                SQL,
            ['user_id' => $userId, 'email' => $email, 'password_hash' => $passwordHash],
        );
    }
}
