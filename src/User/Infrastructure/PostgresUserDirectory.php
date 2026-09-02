<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
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
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO users (auth_subject, email)
                VALUES (:subject, :email)
                ON CONFLICT (auth_subject) DO UPDATE
                    SET email = EXCLUDED.email,
                        updated_at = now()
                RETURNING id, auth_subject, email, display_name
                SQL,
            [
                'subject' => $identity->userId,
                'email' => $identity->email,
            ],
        );

        if ($row === false) {
            throw new RuntimeException('Provisioning the platform user returned no row.');
        }

        $id = $row['id'] ?? null;
        $subject = $row['auth_subject'] ?? null;
        $email = $row['email'] ?? null;
        $displayName = $row['display_name'] ?? null;

        if (!is_string($id) || !is_string($subject)) {
            throw new RuntimeException('The users table returned an unexpected row shape.');
        }

        return new PlatformUser(
            $id,
            $subject,
            is_string($email) ? $email : null,
            is_string($displayName) ? $displayName : null,
        );
    }
}
