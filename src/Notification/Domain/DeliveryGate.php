<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Whether a delivery may be attempted at all (§27.1).
 *
 * Three questions, in an order that matters:
 *
 *   1. Is this a security notice? Then it goes, whatever anyone set.
 *   2. Has the recipient muted this category on this channel?
 *   3. Does this channel need consent, and is there any?
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
        // Security first, and unconditionally. A notice the recipient can
        // mute is one an attacker can mute, and an attacker with the account
        // can change preferences (non-negotiable #24).
        if (!Category::isMutable($category)) {
            return $hasAddress ? null : Delivery::NO_ADDRESS;
        }

        if (!$this->allows($category, $channel)) {
            return Delivery::OPTED_OUT;
        }

        if (Channel::requiresConsent($channel) && !$hasConsent) {
            return Delivery::NO_CONSENT;
        }

        if (!$hasAddress) {
            return Delivery::NO_ADDRESS;
        }

        return null;
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
