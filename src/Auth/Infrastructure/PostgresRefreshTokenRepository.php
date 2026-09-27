<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\StoredRefreshToken;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

final class PostgresRefreshTokenRepository implements RefreshTokenRepository
{
    /**
     * Everything the service asks of a row, computed by the clock that wrote
     * it. Comparing in PHP would compare the database's timestamps against
     * the web server's idea of now.
     */
    private const COLUMNS = <<<'SQL'
        SELECT t.id,
               t.user_id,
               t.token_hash,
               t.family_id,
               t.replaced_by,
               u.auth_subject,
               u.email,
               (t.revoked_at IS NOT NULL) AS revoked,
               (t.expires_at <= now()) AS expired,
               CAST(floor(extract(epoch FROM now() - t.revoked_at)) AS integer) AS revoked_seconds_ago,
               CAST(floor(extract(epoch FROM now() - t.family_started_at)) AS integer) AS family_age_seconds,
               CAST(floor(extract(epoch FROM t.expires_at - now())) AS integer) AS expires_in_seconds
          FROM auth_refresh_tokens t
          JOIN users u ON u.id = t.user_id
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function start(string $userId, #[SensitiveParameter] string $tokenHash, int $lifetimeSeconds): string
    {
        // Its own family: `family_id` is the id this row is given, which
        // needs the id before the insert — so it is generated here, by the
        // database, in the same statement.
        $id = $this->connection->fetchOne(
            <<<'SQL'
                WITH new AS (SELECT gen_random_uuid() AS id)
                INSERT INTO auth_refresh_tokens (id, user_id, token_hash, expires_at, family_id, family_started_at)
                SELECT new.id, :user_id, :token_hash, now() + make_interval(secs => :lifetime), new.id, now()
                  FROM new
                RETURNING id
                SQL,
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'lifetime' => $lifetimeSeconds],
        );

        return is_string($id) ? $id : throw new \RuntimeException('Could not issue a refresh token.');
    }

    public function find(#[SensitiveParameter] string $tokenHash): ?StoredRefreshToken
    {
        $row = $this->connection->fetchAssociative(
            self::COLUMNS . ' WHERE t.token_hash = :token_hash AND u.erased_at IS NULL',
            ['token_hash' => $tokenHash],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function findById(string $id): ?StoredRefreshToken
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            self::COLUMNS . ' WHERE t.id = :id AND u.erased_at IS NULL',
            ['id' => $id],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function rotate(string $id, #[SensitiveParameter] string $successorHash, int $lifetimeSeconds, int $maxAgeSeconds): ?string
    {
        return $this->connection->transactional(function () use ($id, $successorHash, $lifetimeSeconds, $maxAgeSeconds): ?string {
            // The row lock is the whole of the concurrency story. A second
            // caller waits here, then reads the token already rotated and
            // returns the replacement the first one wrote.
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT (revoked_at IS NULL) AS live,
                           (expires_at > now()) AS unexpired,
                           (family_started_at + make_interval(secs => :max_age) > now()) AS young,
                           replaced_by
                      FROM auth_refresh_tokens
                     WHERE id = :id
                       FOR UPDATE
                    SQL,
                ['id' => $id, 'max_age' => $maxAgeSeconds],
            );

            if ($row === false) {
                return null;
            }

            if (!(bool) $row['live']) {
                return Row::nullableString($row, 'replaced_by');
            }

            if (!(bool) $row['unexpired'] || !(bool) $row['young']) {
                return null;
            }

            // Capped by the sign-in's age, so the replacement cannot outlive
            // the session it continues. `young` above guarantees the cap is in
            // the future, which the table's `expires_after_issue` requires.
            $successor = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO auth_refresh_tokens (user_id, token_hash, expires_at, family_id, family_started_at)
                    SELECT user_id, :hash,
                           least(now() + make_interval(secs => :lifetime),
                                 family_started_at + make_interval(secs => :max_age)),
                           family_id, family_started_at
                      FROM auth_refresh_tokens
                     WHERE id = :id
                    RETURNING id
                    SQL,
                ['id' => $id, 'hash' => $successorHash, 'lifetime' => $lifetimeSeconds, 'max_age' => $maxAgeSeconds],
            );

            if (!is_string($successor)) {
                throw new \RuntimeException('Could not rotate a refresh token.');
            }

            $this->connection->executeStatement(
                'UPDATE auth_refresh_tokens SET revoked_at = now(), replaced_by = :successor WHERE id = :id',
                ['id' => $id, 'successor' => $successor],
            );

            return $successor;
        });
    }

    public function revokeFamily(string $familyId): int
    {
        if (!Uuid::isValid($familyId)) {
            return 0;
        }

        // `revoked_at IS NULL` in the WHERE, so revoking twice cannot move a
        // date: the first revocation is when a token stopped being usable.
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE auth_refresh_tokens
                   SET revoked_at = now()
                 WHERE family_id = :family
                   AND revoked_at IS NULL
                SQL,
            ['family' => $familyId],
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

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): StoredRefreshToken
    {
        $revokedAgo = $row['revoked_seconds_ago'] ?? null;

        return new StoredRefreshToken(
            Row::string($row, 'id'),
            Row::string($row, 'user_id'),
            Row::string($row, 'auth_subject'),
            Row::nullableString($row, 'email'),
            (bool) ($row['revoked'] ?? false),
            (bool) ($row['expired'] ?? true),
            Row::string($row, 'family_id'),
            Row::nullableString($row, 'replaced_by'),
            is_numeric($revokedAgo) ? (int) $revokedAgo : null,
            is_numeric($row['family_age_seconds'] ?? null) ? (int) $row['family_age_seconds'] : 0,
            is_numeric($row['expires_in_seconds'] ?? null) ? (int) $row['expires_in_seconds'] : 0,
            Row::string($row, 'token_hash'),
        );
    }
}
