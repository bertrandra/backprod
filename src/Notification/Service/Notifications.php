<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\Consent;
use App\Notification\Domain\Delivery;
use App\Notification\Domain\DeliveryGate;
use App\Notification\Domain\Notification;
use App\Notification\Domain\NotificationRepository;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Raising notifications and letting people govern their own (§27.1).
 *
 * `raise` decides which channels a notification is *created for*, and does it
 * up front rather than at send time. The reason is that a suppression must be
 * recorded: writing a delivery row per candidate channel — some of which will
 * be suppressed — is what makes "did we tell them, and if not why?"
 * answerable. Deciding at send time and skipping the ones that fail the gate
 * would leave nothing behind.
 */
final class Notifications
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    /**
     * Creates a notification and the delivery rows it will be attempted on.
     *
     * Participates in the caller's transaction: a payment failure and the
     * notice about it commit together, or neither does.
     *
     * @param array<string, mixed> $payload
     * @param list<string>|null    $channels null means the default set
     */
    public function raise(
        string $tenantId,
        string $productId,
        string $recipientUserId,
        string $type,
        string $category,
        array $payload = [],
        ?string $dedupKey = null,
        bool $legalEffect = false,
        ?array $channels = null,
    ): ?Notification {
        return $this->notifications->raise(
            $tenantId,
            $productId,
            $recipientUserId,
            $type,
            $category,
            $payload,
            $dedupKey,
            $legalEffect,
            $channels ?? self::defaultChannelsFor($category),
        );
    }

    /**
     * Which channels a category is attempted on when the caller does not say.
     *
     * The screen is always included: it is the one channel that cannot be
     * refused for want of an address, so a notification always has somewhere
     * to land. Email joins it for everything a person would want out of band.
     * SMS and WhatsApp are never a default — they cost money and they need
     * consent, so they are opted into per notification, never assumed.
     *
     * @return list<string>
     */
    public static function defaultChannelsFor(string $category): array
    {
        if ($category === Category::MARKETING) {
            // Marketing does not belong in an operational inbox.
            return [Channel::EMAIL];
        }

        return [Channel::SCREEN, Channel::EMAIL];
    }

    /**
     * @return array{notifications: list<Notification>, total: int, unread: int}
     */
    public function listFor(
        string $recipientUserId,
        string $productId,
        bool $unreadOnly,
        int $limit,
        int $offset,
    ): array {
        return $this->notifications->listFor($recipientUserId, $productId, $unreadOnly, $limit, $offset);
    }

    public function unreadCount(string $recipientUserId, string $productId): int
    {
        return $this->notifications->unreadCount($recipientUserId, $productId);
    }

    public function markRead(string $recipientUserId, string $productId, string $notificationId): Notification
    {
        $notification = $this->notifications->markRead($recipientUserId, $productId, $notificationId);

        if ($notification === null) {
            // The same answer for "not yours" as for "does not exist". An id
            // is not an authorisation, and telling the two apart would let a
            // caller enumerate other people's notifications.
            throw new NotFoundException('Notification not found.', [], 'NOTIFICATION_NOT_FOUND');
        }

        return $notification;
    }

    public function markAllRead(string $recipientUserId, string $productId): int
    {
        return $this->notifications->markAllRead($recipientUserId, $productId);
    }

    /**
     * @return list<Delivery>
     */
    public function deliveriesFor(string $recipientUserId, string $productId, string $notificationId): array
    {
        $notification = $this->notifications->find($recipientUserId, $productId, $notificationId);

        if ($notification === null) {
            throw new NotFoundException('Notification not found.', [], 'NOTIFICATION_NOT_FOUND');
        }

        return $this->notifications->deliveriesFor($notification->id);
    }

    /**
     * @return array<string, bool>
     */
    public function preferences(string $userId, string $productId): array
    {
        return $this->notifications->preferencesFor($userId, $productId);
    }

    public function setPreference(
        string $userId,
        string $productId,
        string $category,
        string $channel,
        bool $enabled,
    ): void {
        if (!Category::isKnown($category)) {
            throw self::invalid('category', 'must be one of ' . implode(', ', Category::all()));
        }

        if (!Channel::isKnown($channel)) {
            throw self::invalid('channel', 'must be one of ' . implode(', ', Channel::all()));
        }

        // Refused here with a message a client can act on, and refused again
        // by a CHECK constraint if anything ever reaches the database another
        // way. Non-negotiable #24.
        if (!Category::isMutable($category) && !$enabled) {
            throw self::invalid('category', 'security notifications cannot be switched off');
        }

        $this->notifications->setPreference($userId, $productId, $category, $channel, $enabled);
    }

    /**
     * @return list<Consent>
     */
    public function consents(string $userId): array
    {
        return $this->notifications->consentsFor($userId);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function grantConsent(
        string $userId,
        string $channel,
        string $purpose,
        string $source,
        array $evidence,
    ): Consent {
        if (!Channel::isKnown($channel) || Channel::isInternal($channel)) {
            throw self::invalid('channel', 'must be an outbound channel');
        }

        if (!in_array($purpose, [Consent::TRANSACTIONAL, Consent::MARKETING], true)) {
            throw self::invalid('purpose', 'must be TRANSACTIONAL or MARKETING');
        }

        return $this->notifications->grantConsent($userId, $channel, $purpose, $source, $evidence);
    }

    public function revokeConsent(string $userId, string $consentId): Consent
    {
        $consent = $this->notifications->revokeConsent($userId, $consentId);

        if ($consent === null) {
            throw new NotFoundException('Consent not found.', [], 'CONSENT_NOT_FOUND');
        }

        return $consent;
    }

    /**
     * The gate for one recipient, built from their stored preferences.
     */
    public function gateFor(string $userId, string $productId): DeliveryGate
    {
        return new DeliveryGate($this->notifications->preferencesFor($userId, $productId));
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }
}
