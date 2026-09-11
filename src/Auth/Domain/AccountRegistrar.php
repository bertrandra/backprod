<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * Creating an account from nothing, which no other port can do.
 *
 * Everywhere else a user already exists: `LocalCredentialRepository::save`
 * sets a password for one, `MemberAdministration::add` adds an existing
 * platform user to a tenant. A stranger arriving at the storefront has
 * neither, and the five rows they need — user, credential, tenant, membership,
 * role — have to appear together or not at all. Half of them is an account
 * that cannot sign in or a tenant nobody administers, and both are worse than
 * a failed sign-up.
 *
 * So this is one method rather than five composed by a service: the
 * transaction is the invariant, and a service orchestrating five ports could
 * not hold one.
 */
interface AccountRegistrar
{
    /**
     * Whether an address is already spoken for.
     *
     * Asked before anything is written, so the answer is a 409 rather than a
     * constraint violation. It is a race — two sign-ups for the same address
     * can both pass this — which is why the unique index stays the authority
     * and {@see register} may still fail.
     */
    public function emailIsTaken(string $email): bool;

    /**
     * The whole account, in one transaction.
     *
     * `organisation` is what the tenant is called. It is optional to the
     * person signing up — a consumer has no company and should not be asked
     * to invent one — and never optional to the platform: an invoice needs a
     * bill-to name, so the caller passes the person's own name when there is
     * no company, and the difference between the two cases is recorded
     * nowhere because there is nothing downstream that should branch on it.
     *
     * A **billing profile** is created with it, which is why `organisation` is
     * not optional here however optional it was on the form. A checkout
     * refuses an order it cannot invoice — numbering is gapless, so a
     * document raised by mistake cannot be deleted — and an account that
     * could not buy anything would make the storefront's promise false. The
     * profile is minimal: who the invoice is addressed to, where to send it,
     * and the country VAT depends on when the person gave one.
     *
     * @param string  $passwordHash from `password_hash()`; the database refuses anything else
     * @param ?string $countryCode  ISO 3166-1 alpha-2, or null when not asked for
     *
     * @throws \App\Shared\Exceptions\ConflictException if the address was taken between the check and here
     * @throws \App\Shared\Exceptions\NotFoundException if the product does not exist or is inactive
     */
    public function register(
        string $email,
        #[SensitiveParameter] string $passwordHash,
        ?string $displayName,
        string $organisation,
        string $productCode,
        ?string $countryCode,
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
