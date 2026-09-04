<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Storage for notifications, their deliveries, preferences and consents.
 *
 * `raise` is a *participating* method: it opens no transaction of its own, so
 * a caller that produces a notification as part of something else — an
 * invoice, a payment failure, a subscription ending — writes both in one
 * transaction. A notification promising to tell somebody about a thing that
 * rolled back is worse than none.
 */
interface NotificationRepository
{
    /**
     * Creates a notification and its pending deliveries.
     *
     * Returns null when a live notification with the same dedup key already
     * exists: a burst of the same event should produce one notice, not three,
     * and the uniqueness is an index rather than a prior check because two
     * runners would each see "none yet" and both write.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $channels
     */
    public function raise(
        string $tenantId,
        string $productId,
        string $recipientUserId,
        string $type,
        string $category,
        array $payload,
        ?string $dedupKey,
        bool $legalEffect,
        array $channels,
    ): ?Notification;

    /**
     * @return array{notifications: list<Notification>, total: int, unread: int}
     */
    public function listFor(
        string $recipientUserId,
        string $productId,
        bool $unreadOnly,
        int $limit,
        int $offset,
    ): array;

    public function find(string $recipientUserId, string $productId, string $notificationId): ?Notification;

    public function markRead(string $recipientUserId, string $productId, string $notificationId): ?Notification;

    public function markAllRead(string $recipientUserId, string $productId): int;

    public function unreadCount(string $recipientUserId, string $productId): int;

    /**
     * @return list<Delivery>
     */
    public function deliveriesFor(string $notificationId): array;

    /**
     * Claims deliveries for sending, oldest first, under a lease.
     *
     * The claim moves each row out of PENDING in the same statement that
     * selects it, so an overlapping runner pass — the normal case on a polled
     * queue — cannot see it at all. That is what makes a duplicate send
     * impossible rather than unlikely: `UNIQUE (notification_id, channel)`
     * says there is one row per channel, and this says one runner holds it.
     *
     * A lease that has lapsed is claimable again, because a runner whose
     * process died is indistinguishable from one that is slow and neither
     * should cost the recipient a notice. `$maxAttempts` is what stops that
     * from repeating for ever on a message that kills whoever picks it up.
     *
     * @return list<array{delivery: Delivery, notification: Notification}>
     */
    public function claimPending(int $limit, int $leaseSeconds, int $maxAttempts): array;

    /**
     * Fails deliveries whose lease lapsed and whose attempts are spent.
     *
     * Without this they would sit claimed for ever, held by a runner that no
     * longer exists — which reads as "in flight" and is really "lost". A
     * recorded failure is the honest answer, and it is the one a pre-renewal
     * notice needs: never sent, and we know it.
     *
     * @return int how many were given up on
     */
    public function abandonExpired(int $maxAttempts): int;

    public function recordSent(string $deliveryId, ?string $providerMessageId, ?string $renderedBody): void;

    public function recordFailure(string $deliveryId, string $failureReason): void;

    public function recordSuppressed(string $deliveryId, string $reason): void;

    /**
     * @return array<string, bool> keyed "CATEGORY:CHANNEL"
     */
    public function preferencesFor(string $userId, string $productId): array;

    public function setPreference(
        string $userId,
        string $productId,
        string $category,
        string $channel,
        bool $enabled,
    ): void;

    /**
     * @return list<Consent>
     */
    public function consentsFor(string $userId): array;

    /**
     * @param array<string, mixed> $evidence
     */
    public function grantConsent(
        string $userId,
        string $channel,
        string $purpose,
        string $source,
        array $evidence,
    ): Consent;

    public function revokeConsent(string $userId, string $consentId): ?Consent;

    public function hasLiveConsent(string $userId, string $channel, string $purpose): bool;
}
