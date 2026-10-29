<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Whether a delivery may be attempted at all (§27.1).
 *
 * Three questions, in an order that matters:
 *
 *   1. Has the recipient muted this category on this channel? A security
 *      notice skips this one question, whatever anyone set.
 *   2. Does this delivery need consent, and is there any? A security notice
 *      does not skip this: an SMS nobody agreed to receive is not made lawful
 *      by being important, and marketing needs its own consent on any
 *      channel that leaves the platform.
 *   3. Is there an address?
 *
 * The default is **closed** for a channel that needs consent. No consent
 * recorded means no attempt — not "try and see", and not a silent drop. The
 * delivery is written SUPPRESSED with its reason, so "did we tell them?" has
 * an answer either way.
 *
 * That is the same posture as VIES being unreachable granting no reverse
 * charge (§25.3) and an absent secret validating no signed link (§31): where
 * the platform cannot establish permission, it does not proceed.
 *
 * The decision lives here rather than in each channel adapter because an
 * adapter that could also refuse would put one decision in two places, and
 * the SMS one is where a mistake costs money.
 */
final class DeliveryGate
{
    /**
     * @param array<string, bool> $preferences keyed "CATEGORY:CHANNEL"
     */
    public function __construct(private readonly array $preferences)
    {
    }

    /**
     * The suppression reason, or null when the delivery may proceed.
     */
    public function refuse(string $category, string $channel, bool $hasConsent, bool $hasAddress): ?string
    {
        // A security notice ignores the preference. A notice the recipient
        // can mute is one an attacker can mute, and an attacker with the
        // account can change preferences (non-negotiable #24).
        if (Category::isMutable($category) && !$this->allows($category, $channel)) {
            return Delivery::OPTED_OUT;
        }

        // Consent is not a preference, and security does not bypass it: a
        // channel that needs consent still needs it for a security notice.
        // Marketing needs a MARKETING consent on every channel that leaves the
        // platform — the inbox has nobody to consent to, and is gated by the
        // preference alone, which is off until chosen.
        if (self::needsConsent($category, $channel) && !$hasConsent) {
            return Delivery::NO_CONSENT;
        }

        if (!$hasAddress) {
            return Delivery::NO_ADDRESS;
        }

        return null;
    }

    private static function needsConsent(string $category, string $channel): bool
    {
        return Channel::requiresConsent($channel)
            || ($category === Category::MARKETING && !Channel::isInternal($channel));
    }

    /**
     * Absent preference means enabled.
     *
     * A person who has never opened the settings should still hear that their
     * payment failed. Opting out is a decision somebody made; silence is not.
     * The one exception is marketing, which is off until chosen — the
     * opposite default, for the opposite reason.
     */
    public function allows(string $category, string $channel): bool
    {
        $key = $category . ':' . $channel;

        if (array_key_exists($key, $this->preferences)) {
            return $this->preferences[$key];
        }

        return $category !== Category::MARKETING;
    }
}
