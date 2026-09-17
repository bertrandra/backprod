<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * Creating an account from nothing, which no other port can do.
 *
 * Everywhere else a user already exists: `LocalCredentialRepository::save`
 * sets a password for one, `MemberAdministration::add` adds an existing
 * platform user to a tenant. A stranger arriving at an organisation's root
 * has neither, and the rows they need — user, credential, a membership per
 * product held, its USER role — have to appear together or not at all. Half
 * of them is an account that cannot sign in, which is worse than a failed
 * sign-up.
 *
 * So this is one method rather than four composed by a service: the
 * transaction is the invariant, and a service orchestrating ports could not
 * hold one.
 */
interface AccountRegistrar
{
    /**
     * Whether an address is already spoken for.
     *
     * Asked before anything is written, so the answer is a 409 rather than a
     * constraint violation. It is a race — two sign-ups for the same address
     * can both pass this — which is why the unique index stays the authority
     * and {@see join} may still fail.
     */
    public function emailIsTaken(string $email): bool;

    /**
     * The account, and a request to join, in one transaction.
     *
     * Since 2026-09-17 a sign-up creates no organisation. The person asks
     * the organisation whose URL root they are on to have them, as a USER.
     * The organisation's join policy decides — the membership is ACTIVE or
     * PENDING, or the request is refused — and the membership is mirrored
     * onto every product the organisation holds (ADR-047). No billing
     * profile is made: the organisation already has one.
     *
     * @param string      $passwordHash from `password_hash()`; the database refuses anything else
     * @param string|null $productCode  the product the person arrived through; their default when the organisation holds it, else the first it holds
     *
     * @throws \App\Shared\Exceptions\ConflictException  if the address was taken between the check and here
     * @throws \App\Shared\Exceptions\NotFoundException  if no organisation has the slug, or it holds no product
     * @throws \App\Shared\Exceptions\ForbiddenException JOIN_BY_INVITATION | JOIN_DOMAIN_NOT_ALLOWED
     */
    public function join(
        string $email,
        #[SensitiveParameter] string $passwordHash,
        ?string $displayName,
        string $tenantSlug,
        ?string $productCode,
    ): RegisteredAccount;

    /**
     * Records a verification token for a user, and forgets any earlier one.
     *
     * One live token per account: a person who asks for a second link has
     * decided the first is lost, and leaving both usable widens the window
     * for no benefit.
     *
     * @param string $tokenHash SHA-256 of the token that was sent
     */
    public function issueVerification(string $userId, string $tokenHash, int $lifetimeSeconds): void;

    /**
     * Marks an address confirmed, if the token is live.
     *
     * False covers unknown, expired and already-used, because a public
     * endpoint answering differently for each is a way to probe them.
     */
    public function confirmEmail(string $tokenHash): bool;
}
