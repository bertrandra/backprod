<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use App\Shared\Exceptions\ForbiddenException;

/**
 * How a person who asks to join an organisation is answered (2026-09-17).
 *
 * The organisation's policy decides, and the three policies are the three
 * honest answers to "who may come in by themselves": nobody (an
 * administrator invites), people from our own domain, or anybody — after
 * an administrator has looked. There is no `OPEN`: a door at
 * `hostname/acme/` that any stranger could walk through by typing an
 * address would make membership, and everything it reads, a matter of
 * knowing a URL.
 */
final class JoinDecision
{
    public const INVITATION = 'INVITATION';
    public const DOMAIN = 'DOMAIN';
    public const APPROVAL = 'APPROVAL';

    public const ACTIVE = 'ACTIVE';
    public const PENDING = 'PENDING';

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::INVITATION, self::DOMAIN, self::APPROVAL];
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
            self::APPROVAL => self::PENDING,
            self::DOMAIN => in_array(self::domainOf($email), $allowedDomains, true)
                ? self::ACTIVE
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

    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }
}
