<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use App\Auth\Domain\AuthenticatedIdentity;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserDirectory;

/**
 * Directory without a database, for tests that exercise the context chain
 * rather than persistence.
 *
 * The provisioned id is the provider subject, so a test can key its fixtures
 * by a readable name instead of a generated UUID. The Postgres directory is
 * covered separately against a real database.
 */
final class InMemoryUserDirectory implements UserDirectory
{
    public function resolve(AuthenticatedIdentity $identity): PlatformUser
    {
        return new PlatformUser($identity->userId, $identity->userId, $identity->email);
    }
}
