<?php

declare(strict_types=1);

namespace App\User\Domain;

use App\Auth\Domain\AuthenticatedIdentity;

interface UserDirectory
{
    /**
     * The platform user for an authenticated identity, creating the record on
     * first sight (ADR-017).
     *
     * Provisioning grants nothing: a new user has no membership and is
     * refused until invited. This answers "who is this, locally", not
     * "what may they do".
     */
    public function resolve(AuthenticatedIdentity $identity): PlatformUser;
}
