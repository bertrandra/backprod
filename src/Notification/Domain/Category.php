<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * What kind of thing a notification is about (§27.1).
 *
 * The category is what a preference switches, not the individual type: a
 * person wants to hear about billing or not, and enumerating every event they
 * might mute is a list that goes stale the first time one is added.
 */
final class Category
{
    public const BILLING = 'BILLING';
    public const ACCOUNT = 'ACCOUNT';
    public const SECURITY = 'SECURITY';
    public const SUPPORT = 'SUPPORT';
    public const MARKETING = 'MARKETING';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::BILLING, self::ACCOUNT, self::SECURITY, self::SUPPORT, self::MARKETING];
    }

    public static function isKnown(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /**
     * Security notices cannot be switched off.
     *
     * A notice the recipient can mute is a notice an attacker can mute — and
     * an attacker who has the account can change preferences. This is the
     * counterpart of non-negotiable #21: staff access to tenant data is never
     * silent, and neither is a new sign-in. Non-negotiable #24.
     */
    public static function isMutable(string $category): bool
    {
        return $category !== self::SECURITY;
    }

    /**
     * Marketing is the one category whose consent is about the purpose rather
     * than the channel, and the one the law treats differently (§26.1).
     */
    public static function purposeOf(string $category): string
    {
        return $category === self::MARKETING ? Consent::MARKETING : Consent::TRANSACTIONAL;
    }
}
