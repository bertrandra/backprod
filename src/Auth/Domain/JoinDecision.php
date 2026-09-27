<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use App\Shared\Exceptions\ForbiddenException;

/**
 * How a person who asks to join an organisation is answered (2026-09-17).
 *
 * The organisation's policy decides, and the four policies are the answers
 * to "who may come in by themselves": nobody (an administrator invites),
 * people from our own domain, anybody after an administrator has looked, or
 * anybody at once — `OPEN`, the default since 2026-09-18, so that a stranger
 * can sign up and pay.
 *
 * **A domain is proved, not typed** (ADR-061, 2026-09-27). `DOMAIN` used to
 * answer `ACTIVE` on the address as entered, and the domain is the only
 * evidence that policy asks for — so `anyone@acme.example` at Acme's root
 * was a member of Acme. It now answers `UNCONFIRMED`, and the membership
 * goes live when the address is proved (`AccountRegistrar::proveAddress`).
 * Not `PENDING`: that is a question put to the administrators, and nobody is
 * asking them anything.
 */
final class JoinDecision
{
    /** In at once, whoever they are — the default since 2026-09-18, so a stranger can sign up and pay. */
    public const OPEN = 'OPEN';
    public const INVITATION = 'INVITATION';
    public const DOMAIN = 'DOMAIN';
    public const APPROVAL = 'APPROVAL';

    public const ACTIVE = 'ACTIVE';
    public const PENDING = 'PENDING';
    public const UNCONFIRMED = 'UNCONFIRMED';

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::OPEN, self::INVITATION, self::DOMAIN, self::APPROVAL];
    }

    /**
     * The membership status a sign-up gets, or a refusal.
     *
     * @param list<string> $allowedDomains lowercase, for the DOMAIN policy
     *
     * @throws ForbiddenException JOIN_BY_INVITATION | JOIN_DOMAIN_NOT_ALLOWED
     */
    public static function statusFor(string $policy, array $allowedDomains, string $email): string
    {
        return match ($policy) {
            self::OPEN => self::ACTIVE,
            self::APPROVAL => self::PENDING,
            self::DOMAIN => self::admitsByDomain($allowedDomains, $email)
                ? self::UNCONFIRMED
                : throw new ForbiddenException(
                    'JOIN_DOMAIN_NOT_ALLOWED',
                    'This organisation accepts people from its own email domains only. Ask an administrator to invite you.',
                    ['domain' => self::domainOf($email)],
                ),
            default => throw new ForbiddenException(
                'JOIN_BY_INVITATION',
                'This organisation is joined by invitation. Ask an administrator to invite you.',
            ),
        };
    }

    /**
     * Whether a `DOMAIN` organisation takes this address — asked at sign-up,
     * and asked again when the address is proved, because the list may have
     * changed in between.
     *
     * @param list<string> $allowedDomains lowercase
     */
    public static function admitsByDomain(array $allowedDomains, string $email): bool
    {
        return in_array(self::domainOf($email), $allowedDomains, true);
    }

    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }
}
