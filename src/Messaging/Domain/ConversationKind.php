<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

/**
 * What a conversation is for, and therefore who may be in it.
 *
 * The distinction is not cosmetic: a STAFF participant is only permitted in a
 * SUPPORT conversation, and the database enforces it through a composite
 * foreign key rather than trusting this constant to be checked (§12.3).
 */
final class ConversationKind
{
    /** A tenant's own members, talking among themselves. */
    public const INTERNAL = 'INTERNAL';

    /** The tenant and the platform, talking to each other. */
    public const SUPPORT = 'SUPPORT';

    public static function isKnown(string $kind): bool
    {
        return $kind === self::INTERNAL || $kind === self::SUPPORT;
    }
}
