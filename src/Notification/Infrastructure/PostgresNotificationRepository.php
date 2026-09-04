<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\Consent;
use App\Notification\Domain\Delivery;
use App\Notification\Domain\Notification;
use App\Notification\Domain\NotificationRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class PostgresNotificationRepository implements NotificationRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, recipient_user_id, type, category,
        payload, dedup_key, legal_effect, created_at, read_at
        SQL;

    private const DELIVERY_COLUMNS = <<<'SQL'
        id, notification_id, channel, status, suppression_reason,
        provider_message_id, attempts, failure_reason, rendered_body,
        sent_at, delivered_at
        SQL;

    private const CONSENT_COLUMNS = 'id, user_id, channel, purpose, granted_at, revoked_at, source, evidence';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
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
    ): ?Notification {
        // No transactional() here on purpose: a caller producing this as part
        // of something else — an invoice, a failed payment — writes both in
        // one transaction. A notification promising to tell somebody about
        // something that rolled back is worse than none.
        //
        // ON CONFLICT DO NOTHING rather than a prior "is there one already?":
        // two runners would each see none and both write. The index decides.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            INSERT INTO notifications
                (tenant_id, product_id, recipient_user_id, type, category,
                 payload, dedup_key, legal_effect)
            VALUES (:tenantId, :productId, :recipient, :type, :category,
                    CAST(:payload AS jsonb), :dedupKey, :legalEffect)
            ON CONFLICT DO NOTHING
            RETURNING
            SQL . ' ' . self::COLUMNS,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'recipient' => $recipientUserId,
                'type' => $type,
                'category' => $category,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'dedupKey' => $dedupKey,
                'legalEffect' => $legalEffect ? 'true' : 'false',
            ],
        );

        if ($row === false) {
            // Deduplicated. Not an error: one notice is the correct answer to
            // three identical events.
            return null;
        }

        $notification = self::toNotification($row);

        foreach ($channels as $channel) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO notification_deliveries (notification_id, channel, status)
                VALUES (:notificationId, :channel, 'PENDING')
                ON CONFLICT (notification_id, channel) DO NOTHING
                SQL,
                ['notificationId' => $notification->id, 'channel' => $channel],
            );
        }

        return $notification;
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
        if (!Uuid::isValid($recipientUserId) || !Uuid::isValid($productId)) {
            return ['notifications' => [], 'total' => 0, 'unread' => 0];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
              FROM notifications
             WHERE recipient_user_id = :recipient
               AND product_id = :productId
               AND (NOT CAST(:unreadOnly AS BOOLEAN) OR read_at IS NULL)
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset
            SQL,
            [
                'recipient' => $recipientUserId,
                'productId' => $productId,
                'unreadOnly' => $unreadOnly ? 'true' : 'false',
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        $total = $this->connection->fetchOne(
            <<<'SQL'
            SELECT count(*) FROM notifications
             WHERE recipient_user_id = :recipient AND product_id = :productId
               AND (NOT CAST(:unreadOnly AS BOOLEAN) OR read_at IS NULL)
            SQL,
            [
                'recipient' => $recipientUserId,
                'productId' => $productId,
                'unreadOnly' => $unreadOnly ? 'true' : 'false',
            ],
        );

        return [
            'notifications' => array_map(self::toNotification(...), $rows),
            'total' => is_numeric($total) ? (int) $total : 0,
            'unread' => $this->unreadCount($recipientUserId, $productId),
        ];
    }

    public function find(string $recipientUserId, string $productId, string $notificationId): ?Notification
    {
        if (!Uuid::isValid($notificationId) || !Uuid::isValid($recipientUserId)) {
            return null;
        }

        // Scoped by recipient, not merely by id. A notification is addressed
        // to one person, and an id is not an authorisation.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
              FROM notifications
             WHERE id = :id AND recipient_user_id = :recipient AND product_id = :productId
            SQL,
            ['id' => $notificationId, 'recipient' => $recipientUserId, 'productId' => $productId],
        );

        return $row === false ? null : self::toNotification($row);
    }

    public function markRead(string $recipientUserId, string $productId, string $notificationId): ?Notification
    {
        if (!Uuid::isValid($notificationId) || !Uuid::isValid($recipientUserId)) {
            return null;
        }

        // coalesce keeps the first read, so reading twice does not move the
        // timestamp. "When did they see it?" has one answer.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            UPDATE notifications
               SET read_at = coalesce(read_at, now())
             WHERE id = :id AND recipient_user_id = :recipient AND product_id = :productId
            RETURNING
            SQL . ' ' . self::COLUMNS,
            ['id' => $notificationId, 'recipient' => $recipientUserId, 'productId' => $productId],
        );

        return $row === false ? null : self::toNotification($row);
    }

    public function markAllRead(string $recipientUserId, string $productId): int
    {
        if (!Uuid::isValid($recipientUserId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $affected = $this->connection->executeStatement(
            <<<'SQL'
            UPDATE notifications SET read_at = now()
             WHERE recipient_user_id = :recipient AND product_id = :productId AND read_at IS NULL
            SQL,
            ['recipient' => $recipientUserId, 'productId' => $productId],
        );

        // DBAL 4 types this int|string.
        return (int) $affected;
    }

    public function unreadCount(string $recipientUserId, string $productId): int
    {
        if (!Uuid::isValid($recipientUserId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            <<<'SQL'
            SELECT count(*) FROM notifications
             WHERE recipient_user_id = :recipient AND product_id = :productId AND read_at IS NULL
            SQL,
            ['recipient' => $recipientUserId, 'productId' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<Delivery>
     */
    public function deliveriesFor(string $notificationId): array
    {
        if (!Uuid::isValid($notificationId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::DELIVERY_COLUMNS
            . ' FROM notification_deliveries WHERE notification_id = :id ORDER BY channel',
            ['id' => $notificationId],
        );

        return array_map(self::toDelivery(...), $rows);
    }

    /**
     * @return list<array{delivery: Delivery, notification: Notification}>
     */
    public function claimPending(int $limit): array
    {
        // FOR UPDATE SKIP LOCKED, as the job queue does (ADR-027): two runner
        // passes overlapping is the normal case on cron, and the second must
        // take different rows rather than wait behind the first or send the
        // same message twice.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            UPDATE notification_deliveries d
               SET attempts = d.attempts + 1, updated_at = now()
             WHERE d.id IN (
                   SELECT id FROM notification_deliveries
                    WHERE status = 'PENDING'
                    ORDER BY created_at
                    FOR UPDATE SKIP LOCKED
                    LIMIT :limit
             )
            RETURNING d.id, d.notification_id, d.channel, d.status, d.suppression_reason,
                      d.provider_message_id, d.attempts, d.failure_reason, d.rendered_body,
                      d.sent_at, d.delivered_at
            SQL,
            ['limit' => $limit],
        );

        $claimed = [];

        foreach ($rows as $row) {
            $delivery = self::toDelivery($row);

            $notificationRow = $this->connection->fetchAssociative(
                'SELECT ' . self::COLUMNS . ' FROM notifications WHERE id = :id',
                ['id' => $delivery->notificationId],
            );

            if ($notificationRow === false) {
                continue;
            }

            $claimed[] = [
                'delivery' => $delivery,
                'notification' => self::toNotification($notificationRow),
            ];
        }

        return $claimed;
    }

    public function recordSent(string $deliveryId, ?string $providerMessageId, ?string $renderedBody): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE notification_deliveries
               SET status = 'SENT', sent_at = now(), provider_message_id = :providerId,
                   rendered_body = :body, failure_reason = NULL, updated_at = now()
             WHERE id = :id
            SQL,
            ['id' => $deliveryId, 'providerId' => $providerMessageId, 'body' => $renderedBody],
        );
    }

    public function recordFailure(string $deliveryId, string $failureReason): void
    {
        // The class of the error, never its message (§31). A provider's
        // message can carry a token or an endpoint, and this field is served
        // over the API.
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE notification_deliveries
               SET status = 'FAILED', failure_reason = :reason, updated_at = now()
             WHERE id = :id
            SQL,
            ['id' => $deliveryId, 'reason' => $failureReason],
        );
    }

    public function recordSuppressed(string $deliveryId, string $reason): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE notification_deliveries
               SET status = 'SUPPRESSED', suppression_reason = :reason, updated_at = now()
             WHERE id = :id
            SQL,
            ['id' => $deliveryId, 'reason' => $reason],
        );
    }

    /**
     * @return array<string, bool>
     */
    public function preferencesFor(string $userId, string $productId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT category, channel, enabled FROM notification_preferences
             WHERE user_id = :userId AND product_id = :productId
            SQL,
            ['userId' => $userId, 'productId' => $productId],
        );

        $preferences = [];

        foreach ($rows as $row) {
            $key = Row::string($row, 'category') . ':' . Row::string($row, 'channel');
            $preferences[$key] = self::boolean($row, 'enabled');
        }

        return $preferences;
    }

    public function setPreference(
        string $userId,
        string $productId,
        string $category,
        string $channel,
        bool $enabled,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO notification_preferences (user_id, product_id, category, channel, enabled)
            VALUES (:userId, :productId, :category, :channel, :enabled)
            ON CONFLICT (user_id, product_id, category, channel)
            DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
            SQL,
            [
                'userId' => $userId,
                'productId' => $productId,
                'category' => $category,
                'channel' => $channel,
                'enabled' => $enabled ? 'true' : 'false',
            ],
        );
    }

    /**
     * @return list<Consent>
     */
    public function consentsFor(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::CONSENT_COLUMNS
            . ' FROM notification_consents WHERE user_id = :userId ORDER BY granted_at DESC',
            ['userId' => $userId],
        );

        return array_map(self::toConsent(...), $rows);
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
        // The partial unique index allows one live consent per (person,
        // channel, purpose), so granting again while one stands returns the
        // one that stands rather than creating a rival.
        $existing = $this->connection->fetchAssociative(
            'SELECT ' . self::CONSENT_COLUMNS . <<<'SQL'
              FROM notification_consents
             WHERE user_id = :userId AND channel = :channel AND purpose = :purpose
               AND revoked_at IS NULL
            SQL,
            ['userId' => $userId, 'channel' => $channel, 'purpose' => $purpose],
        );

        if ($existing !== false) {
            return self::toConsent($existing);
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            INSERT INTO notification_consents (user_id, channel, purpose, source, evidence)
            VALUES (:userId, :channel, :purpose, :source, CAST(:evidence AS jsonb))
            RETURNING
            SQL . ' ' . self::CONSENT_COLUMNS,
            [
                'userId' => $userId,
                'channel' => $channel,
                'purpose' => $purpose,
                'source' => $source,
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            ],
        );

        if ($row === false) {
            throw new RuntimeException('The consent could not be recorded.');
        }

        return self::toConsent($row);
    }

    public function revokeConsent(string $userId, string $consentId): ?Consent
    {
        if (!Uuid::isValid($consentId) || !Uuid::isValid($userId)) {
            return null;
        }

        // Dated, never deleted. Erasing the row would destroy the record that
        // permission once existed, which is the opposite of proof.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            UPDATE notification_consents
               SET revoked_at = coalesce(revoked_at, now())
             WHERE id = :id AND user_id = :userId
            RETURNING
            SQL . ' ' . self::CONSENT_COLUMNS,
            ['id' => $consentId, 'userId' => $userId],
        );

        return $row === false ? null : self::toConsent($row);
    }

    public function hasLiveConsent(string $userId, string $channel, string $purpose): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        $found = $this->connection->fetchOne(
            <<<'SQL'
            SELECT 1 FROM notification_consents
             WHERE user_id = :userId AND channel = :channel AND purpose = :purpose
               AND revoked_at IS NULL
             LIMIT 1
            SQL,
            ['userId' => $userId, 'channel' => $channel, 'purpose' => $purpose],
        );

        return $found !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toNotification(array $row): Notification
    {
        $payload = $row['payload'] ?? null;
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return new Notification(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'recipient_user_id'),
            Row::string($row, 'type'),
            Row::string($row, 'category'),
            is_array($decoded) ? $decoded : [],
            Row::nullableString($row, 'dedup_key'),
            self::boolean($row, 'legal_effect'),
            Row::timestamp($row, 'created_at'),
            Row::nullableTimestamp($row, 'read_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toDelivery(array $row): Delivery
    {
        return new Delivery(
            Row::string($row, 'id'),
            Row::string($row, 'notification_id'),
            Row::string($row, 'channel'),
            Row::string($row, 'status'),
            Row::nullableString($row, 'suppression_reason'),
            Row::nullableString($row, 'provider_message_id'),
            Row::integer($row, 'attempts'),
            Row::nullableString($row, 'failure_reason'),
            Row::nullableString($row, 'rendered_body'),
            Row::nullableTimestamp($row, 'sent_at'),
            Row::nullableTimestamp($row, 'delivered_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toConsent(array $row): Consent
    {
        $evidence = $row['evidence'] ?? null;
        $decoded = is_string($evidence) ? json_decode($evidence, true) : null;

        return new Consent(
            Row::string($row, 'id'),
            Row::string($row, 'user_id'),
            Row::string($row, 'channel'),
            Row::string($row, 'purpose'),
            Row::timestamp($row, 'granted_at'),
            Row::nullableTimestamp($row, 'revoked_at'),
            Row::string($row, 'source'),
            is_array($decoded) ? $decoded : [],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function boolean(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
