<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\StoredRefreshToken;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

final class PostgresRefreshTokenRepository implements RefreshTokenRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(string $userId, #[SensitiveParameter] string $tokenHash, int $lifetimeSeconds): string
    {
        // `now()` and the interval are computed by the database, so the lifetime
        // is measured against the one clock every row in this table was written
        // by. A web server whose clock has drifted would otherwise issue tokens
        // that expire at times unrelated to each other.
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO auth_refresh_tokens (user_id, token_hash, expires_at)
                VALUES (:user_id, :token_hash, now() + make_interval(secs => :lifetime))
                RETURNING id
                SQL,
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'lifetime' => $lifetimeSeconds],
        );

        return is_string($id) ? $id : throw new \RuntimeException('Could not issue a refresh token.');
    }

    public function find(#[SensitiveParameter] string $tokenHash): ?StoredRefreshToken
    {
        // `revoked` and `expired` are computed here, by the same clock that wrote
        // `expires_at`. Comparing in PHP would compare the database's timestamp
        // against the web server's idea of now.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT t.id,
                       t.user_id,
                       u.auth_subject,
                       u.email,
                       (t.revoked_at IS NOT NULL) AS revoked,
                       (t.expires_at <= now()) AS expired
                FROM auth_refresh_tokens t
                JOIN users u ON u.id = t.user_id
                WHERE t.token_hash = :token_hash
                  AND u.erased_at IS NULL
                SQL,
            ['token_hash' => $tokenHash],
        );

        if ($row === false) {
            return null;
        }

        return new StoredRefreshToken(
            Row::string($row, 'id'),
            Row::string($row, 'user_id'),
            Row::string($row, 'auth_subject'),
            Row::nullableString($row, 'email'),
            (bool) ($row['revoked'] ?? false),
            (bool) ($row['expired'] ?? true),
        );
    }

    public function revoke(string $id, ?string $replacedBy): void
    {
        // `revoked_at IS NULL` in the WHERE, so revoking twice cannot move the
        // date: the first revocation is when this token stopped being usable, and
        // a later write would erase that fact.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE auth_refresh_tokens
                SET revoked_at = now(),
                    replaced_by = :replaced_by
                WHERE id = :id
                  AND revoked_at IS NULL
                SQL,
            ['id' => $id, 'replaced_by' => $replacedBy],
        );
    }

    public function revokeAllFor(string $userId): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE auth_refresh_tokens
                SET revoked_at = now()
                WHERE user_id = :user_id
                  AND revoked_at IS NULL
                SQL,
            ['user_id' => $userId],
        );
    }
}
