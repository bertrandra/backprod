<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Where a notification can be delivered (§27.1).
 *
 * The screen channel is the only one that does not leave the platform. It is
 * also the only one that cannot be refused for want of consent or an address,
 * which makes it the natural floor: whatever else is muted or impossible, the
 * notice is still reachable.
 */
final class Channel
{
    public const SCREEN = 'SCREEN';
    public const EMAIL = 'EMAIL';
    public const SMS = 'SMS';
    public const WHATSAPP = 'WHATSAPP';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SCREEN, self::EMAIL, self::SMS, self::WHATSAPP];
    }

    public static function isKnown(string $channel): bool
    {
        return in_array($channel, self::all(), true);
    }

    /**
     * Channels that require a recorded, revocable opt-in before anything is
     * attempted. Email is not on this list for transactional messages — a
     * customer who bought something is reachable about that purchase — but
     * SMS and WhatsApp are, and marketing on any channel is handled by the
     * purpose rather than by the channel.
     */
    public static function requiresConsent(string $channel): bool
    {
        return $channel === self::SMS || $channel === self::WHATSAPP;
    }

    /**
     * The channel that never leaves the platform, and so never fails for a
     * reason outside it.
     */
    public static function isInternal(string $channel): bool
    {
        return $channel === self::SCREEN;
    }
}
