<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Notification\Domain\Category;
use App\Notification\Domain\Consent;
use App\Notification\Domain\Channel;
use App\Notification\Domain\Delivery;
use App\Notification\Domain\DeliveryGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate decides whether a delivery is attempted at all, so what it
 * refuses matters more than what it allows (§37.4).
 */
#[CoversClass(DeliveryGate::class)]
#[CoversClass(Category::class)]
#[CoversClass(Channel::class)]
final class DeliveryGateTest extends TestCase
{
    // --- what must not be sent -------------------------------------------

    public function testSmsWithoutConsentIsNeverAttempted(): void
    {
        // Fail closed. No consent recorded means no attempt — not "try and
        // see", and not a silent drop.
        self::assertSame(
            Delivery::NO_CONSENT,
            $this->gate()->refuse(Category::BILLING, Channel::SMS, false, true),
        );
    }

    public function testWhatsappWithoutConsentIsNeverAttempted(): void
    {
        self::assertSame(
            Delivery::NO_CONSENT,
            $this->gate()->refuse(Category::BILLING, Channel::WHATSAPP, false, true),
        );
    }

    public function testAMutedCategoryIsSuppressedOnThatChannel(): void
    {
        $gate = $this->gate([Category::BILLING . ':' . Channel::EMAIL => false]);

        self::assertSame(
            Delivery::OPTED_OUT,
            $gate->refuse(Category::BILLING, Channel::EMAIL, false, true),
        );
    }

    public function testMutingOneChannelLeavesTheOthersAlone(): void
    {
        $gate = $this->gate([Category::BILLING . ':' . Channel::EMAIL => false]);

        // One failing channel must not lose the notification.
        self::assertNull($gate->refuse(Category::BILLING, Channel::SCREEN, false, true));
    }

    public function testMarketingIsOffUntilChosen(): void
    {
        // The opposite default from everything else, and deliberately so.
        self::assertSame(
            Delivery::OPTED_OUT,
            $this->gate()->refuse(Category::MARKETING, Channel::EMAIL, false, true),
        );
    }

    public function testNoAddressIsRecordedRatherThanAttempted(): void
    {
        self::assertSame(
            Delivery::NO_ADDRESS,
            $this->gate()->refuse(Category::BILLING, Channel::EMAIL, false, false),
        );
    }

    // --- what must be sent anyway ----------------------------------------

    public function testSecurityCannotBeMuted(): void
    {
        $gate = $this->gate([Category::SECURITY . ':' . Channel::EMAIL => false]);

        // A notice the recipient can mute is one an attacker can mute, and an
        // attacker with the account can change preferences (non-negotiable
        // #24). The preference is ignored, and the database refuses to store
        // it in the first place.
        self::assertNull($gate->refuse(Category::SECURITY, Channel::EMAIL, false, true));
    }

    public function testSecurityBeatsEvenAMissingConsent(): void
    {
        self::assertNull($this->gate()->refuse(Category::SECURITY, Channel::SMS, false, true));
    }

    public function testSecurityStillNeedsSomewhereToSend(): void
    {
        // Unmutable is not the same as deliverable.
        self::assertSame(
            Delivery::NO_ADDRESS,
            $this->gate()->refuse(Category::SECURITY, Channel::EMAIL, false, false),
        );
    }

    public function testAnUntouchedPreferenceMeansEnabled(): void
    {
        // Somebody who never opened the settings should still hear that their
        // payment failed. Opting out is a decision; silence is not.
        self::assertNull($this->gate()->refuse(Category::BILLING, Channel::EMAIL, false, true));
    }

    public function testConsentUnlocksAConsentedChannel(): void
    {
        self::assertNull($this->gate()->refuse(Category::BILLING, Channel::SMS, true, true));
    }

    public function testMarketingSendsOnceChosen(): void
    {
        $gate = $this->gate([Category::MARKETING . ':' . Channel::EMAIL => true]);

        self::assertNull($gate->refuse(Category::MARKETING, Channel::EMAIL, false, true));
    }

    // --- the classification the gate rests on ----------------------------

    public function testOnlySmsAndWhatsappRequireConsent(): void
    {
        self::assertTrue(Channel::requiresConsent(Channel::SMS));
        self::assertTrue(Channel::requiresConsent(Channel::WHATSAPP));
        self::assertFalse(Channel::requiresConsent(Channel::EMAIL));
        // The screen never leaves the platform, so there is nobody to consent
        // to.
        self::assertFalse(Channel::requiresConsent(Channel::SCREEN));
    }

    public function testOnlySecurityIsImmutable(): void
    {
        self::assertFalse(Category::isMutable(Category::SECURITY));

        foreach ([Category::BILLING, Category::ACCOUNT, Category::SUPPORT, Category::MARKETING] as $category) {
            self::assertTrue(Category::isMutable($category));
        }
    }

    public function testMarketingIsTheOnlyMarketingPurpose(): void
    {
        // The law treats the purpose differently from the channel (§26.1),
        // so the purpose is derived from the category rather than assumed.
        self::assertSame(Consent::MARKETING, Category::purposeOf(Category::MARKETING));
        self::assertSame(Consent::TRANSACTIONAL, Category::purposeOf(Category::BILLING));
    }

    /**
     * @param array<string, bool> $preferences
     */
    private function gate(array $preferences = []): DeliveryGate
    {
        return new DeliveryGate($preferences);
    }
}
