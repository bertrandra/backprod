<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\UnauthenticatedException;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserDirectory;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class PostgresUserDirectory implements UserDirectory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolve(AuthenticatedIdentity $identity): PlatformUser
    {
        // One statement rather than select-then-insert: two concurrent first
        // requests from the same person would otherwise race, and the loser
        // would get a unique-violation 500 on an ordinary sign-in.
        //
        // DO UPDATE rather than DO NOTHING because DO NOTHING returns no row
        // on conflict, which would leave the common path — an existing user —
        // needing a second query.
        //
        // A subject this platform issued (`local:<id>`) names a row, and
        // never provisions one (2026-10-29). Erasure renames that row's
        // subject to `erased:<id>`, so an access token still in its hour of
        // life no longer matched it — and was answered with a brand new user
        // carrying the erased address. Insert nothing when the id it names is
        // erased; no row back means no such person, and the request is
        // unauthenticated.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO users (auth_subject, email)
                SELECT CAST(:subject AS TEXT), CAST(:email AS TEXT)
                 WHERE NOT EXISTS (
                       SELECT 1 FROM users erased
                        WHERE erased.id = CAST(:localId AS uuid)
                          AND erased.erased_at IS NOT NULL
                 )
                ON CONFLICT (auth_subject) DO UPDATE
                    SET email = EXCLUDED.email,
                        updated_at = now()
                RETURNING id, auth_subject, email, display_name, locale,
                          (email_verified_at IS NULL AND email_confirm_by IS NOT NULL
                           AND email_confirm_by <= now()) AS address_overdue
                SQL,
            [
                'subject' => $identity->userId,
                'email' => $identity->email,
                'localId' => self::localIdOf($identity->userId),
            ],
        );

        if ($row === false) {
            // Only the erased guard can leave the statement without a row.
            throw new UnauthenticatedException();
        }

        $id = $row['id'] ?? null;
        $subject = $row['auth_subject'] ?? null;
        $email = $row['email'] ?? null;
        $displayName = $row['display_name'] ?? null;

        if (!is_string($id) || !is_string($subject)) {
            throw new RuntimeException('The users table returned an unexpected row shape.');
        }

        $locale = $row['locale'] ?? null;

        return new PlatformUser(
            $id,
            $subject,
            is_string($email) ? $email : null,
            is_string($displayName) ? $displayName : null,
            // The default product is not read here: this row exists to say
            // *who* is asking, and the landing reads the default from its
            // own route. The language is, since 2026-09-24 — every request
            // context carries it, so a catalogue can answer in it without a
            // second read.
            null,
            is_string($locale) ? $locale : 'en',
            // Read here, on the row every request already fetches, so the
            // deadline costs no query of its own (ADR-061).
            (bool) ($row['address_overdue'] ?? false),
        );
    }

    /** The user id a subject of this platform's own names, or null for any other. */
    private static function localIdOf(string $subject): ?string
    {
        if (!str_starts_with($subject, 'local:')) {
            return null;
        }

        $id = substr($subject, strlen('local:'));

        return Uuid::isValid($id) ? $id : null;
    }
}
