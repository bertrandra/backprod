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
     * Records a password link for a user — a reset, or an invitation to set
     * one for the first time — and forgets any earlier one (2026-09-19).
     *
     * @param string $tokenHash SHA-256 of the token that was sent
     * @param string $purpose   `RESET` or `INVITATION`
     */
    public function issuePasswordLink(string $userId, string $tokenHash, int $lifetimeSeconds, string $purpose): void;

    /**
     * Spends a password link, if it is live, and says whose it was.
     *
     * One statement, so a link opened twice consumes once. Unknown, expired
     * and spent are one answer — null — for the reason {@see confirmEmail}
     * gives.
     */
    public function consumePasswordLink(string $tokenHash): ?string;

    /** The address of a live account, or null for an unknown or erased one. */
    public function emailOf(string $userId): ?string;

    /**
     * Where a person lives, for a link that has to land somewhere: the
     * organisation of their first live membership and a product it holds.
     * Null for somebody with no membership at all — who has no root to be
     * sent to and no notification context to be told in.
     *
     * @return array{tenant_id: string, product_id: string, slug: string, is_default: bool}|null
     */
    public function homeOf(string $userId): ?array;

    /**
     * An account for a person somebody else named by address (2026-09-19):
     * the owner of a subscription adding them to it. If the address has an
     * account already, that account; if not, a new one with no usable
     * password — the invitation link is how they set one — and, either way,
     * a live USER membership of the organisation on every product it holds.
     * Never an administrator, never a request to be approved: the owner's
     * invitation is the approval.
     *
     * @return array{user_id: string, created: bool}
     */
    public function invite(string $email, string $tenantId): array;

    /**
     * Marks an address confirmed, if the token is live.
     *
     * False covers unknown, expired and already-used, because a public
     * endpoint answering differently for each is a way to probe them.
     */
    public function confirmEmail(string $tokenHash): bool;
}
