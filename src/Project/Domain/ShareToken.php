<?php

declare(strict_types=1);

namespace App\Project\Domain;

use SensitiveParameter;

/**
 * The secret in a share link (ADR-073).
 *
 * 256 bits from the CSPRNG, base64url without padding — 43 characters that
 * survive a URL fragment, a chat message and a copy-paste without escaping.
 * Only {@see hash()} of it is ever stored, so the table holds nothing that can
 * be presented.
 *
 * A plain SHA-256 rather than a keyed hash or a password hash, the same choice
 * `refresh_tokens` and the address confirmation make: the input is 256 random
 * bits, so there is nothing to brute-force that a slower hash would protect,
 * and a lookup must be one indexed equality.
 */
final class ShareToken
{
    /** The length of {@see mint()}'s output; anything else is not a token this platform issued. */
    public const LENGTH = 43;

    public static function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Whether a string could be a token at all, checked before it reaches a
     * query — so a malformed one costs no more than an unknown one, and both
     * answer the same.
     */
    public static function isWellFormed(#[SensitiveParameter] string $token): bool
    {
        return strlen($token) === self::LENGTH && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
    }
}
