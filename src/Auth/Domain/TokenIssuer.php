<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Port for minting an access token (U12).
 *
 * ADR-014 said this platform verifies tokens and never issues them, because
 * identity belonged to an external provider. U12 supersedes that half: the
 * deployment target offers PHP, PostgreSQL and JavaScript, and an external
 * issuer was the only thing reaching outside all three.
 *
 * What ADR-014 got right is unchanged and is why this is a port: the domain
 * knows a caller presents a credential and this platform can mint one. That it
 * is a JWT, signed with HS256, lives in Infrastructure — so a deployment that
 * moves back to an external provider changes an adapter and a container line.
 */
interface TokenIssuer
{
    /**
     * @param string $authSubject the value the token's `sub` carries, which is
     *                            what `users.auth_subject` matches on
     */
    public function issue(string $authSubject, ?string $email): AccessToken;
}
