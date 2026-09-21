<?php

declare(strict_types=1);

namespace App\Webhook\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Webhook\Domain\ProductEventType;
use App\Webhook\Domain\WebhookDeliveries;
use App\Webhook\Domain\WebhookDelivery;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The outbox in PostgreSQL (ADR-051 §5).
 *
 * Claiming is one UPDATE over a `FOR UPDATE SKIP LOCKED` subselect, the
 * queue's own pattern (ADR-027): two runners racing take different rows,
 * and a runner that dies holds its rows only until the lease lapses. The
 * attempt is counted at claim, so a delivery that crashes whoever sends it
 * still spends one.
 */
final class PostgresWebhookDeliveries implements WebhookDeliveries
{
    private const CURSOR = 'subscription_events';

    /** How far behind the clock the collector reads, so a late commit is not passed over. */
    private const MARGIN = '1 minute';

    private const BATCH = 500;

    private const COLUMNS = <<<'SQL'
        d.id, d.product_id, p.code AS product_code, d.event_id, d.event_type, d.tenant_id, d.payload,
        d.occurred_at, d.attempt, d.next_attempt_at, d.delivered_at, d.parked_at, d.last_status, d.last_error, d.created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function collectSubscriptionEvents(): int
    {
        return $this->connection->transactional(function (): int {
            $position = $this->connection->fetchOne(
                'SELECT position FROM webhook_cursors WHERE name = :name FOR UPDATE',
                ['name' => self::CURSOR],
            );

            if (!is_string($position)) {
                // First run: nothing that happened before products could be
                // told is news. The cursor starts now.
                $this->connection->executeStatement(
                    'INSERT INTO webhook_cursors (name, position) VALUES (:name, now()) ON CONFLICT (name) DO NOTHING',
                    ['name' => self::CURSOR],
                );

                return 0;
            }

            // `>=` rather than `>`: events in one transaction share a
            // timestamp, and a batch cut between two of them must not lose
            // the second. The unique event id makes the overlap harmless.
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id, e.type, e.occurred_at,
                           s.id AS subscription_id, s.tenant_id, s.product_id, s.status, s.current_period_end,
                           s.subscriber_kind, s.subscriber_user_id, o.code AS offer_code,
                           (p.active AND p.webhook_url IS NOT NULL) AS addressed
                      FROM subscription_events e
                      JOIN subscriptions s ON s.id = e.subscription_id
                      JOIN offer_versions v ON v.id = s.offer_version_id
                      JOIN offers o ON o.id = v.offer_id
                      JOIN products p ON p.id = s.product_id
                     WHERE e.occurred_at >= :position
                       AND e.occurred_at <= now() - CAST(:margin AS interval)
                     ORDER BY e.occurred_at, e.id
                     LIMIT :limit
                    SQL,
                ['position' => $position, 'margin' => self::MARGIN, 'limit' => self::BATCH],
            );

            $written = 0;
            $last = null;

            foreach ($rows as $row) {
                $last = Row::string($row, 'occurred_at');
                $type = ProductEventType::ofSubscriptionEvent(Row::string($row, 'type'));

                if ($type === null || !Row::boolean($row, 'addressed')) {
                    continue;
                }

                $userId = Row::nullableString($row, 'subscriber_user_id');
                $detail = [
                    'subscription' => [
                        'id' => Row::string($row, 'subscription_id'),
                        'status' => Row::string($row, 'status'),
                        'offer' => Row::string($row, 'offer_code'),
                        'current_period_end' => Row::nullableTimestamp($row, 'current_period_end')?->format(DATE_ATOM),
                        'subscriber' => ['kind' => Row::string($row, 'subscriber_kind'), 'user_id' => $userId],
                    ],
                ];

                $written += (int) $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO webhook_deliveries (product_id, event_id, event_type, tenant_id, payload, occurred_at)
                        VALUES (:product, :event, :type, :tenant, CAST(:payload AS jsonb), :occurredAt)
                        ON CONFLICT (event_id) DO NOTHING
                        SQL,
                    [
                        'product' => Row::string($row, 'product_id'),
                        'event' => Row::string($row, 'id'),
                        'type' => $type,
                        'tenant' => Row::string($row, 'tenant_id'),
                        'payload' => json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'occurredAt' => $last,
                    ],
                );
            }

            if ($last !== null) {
                $this->connection->executeStatement(
                    'UPDATE webhook_cursors SET position = :position, updated_at = now() WHERE name = :name',
                    ['position' => $last, 'name' => self::CURSOR],
                );
            }

            return $written;
        });
    }

    public function claimDue(int $limit, int $leaseSeconds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH due AS (
                    SELECT id
                      FROM webhook_deliveries
                     WHERE delivered_at IS NULL AND parked_at IS NULL AND next_attempt_at <= now()
                     ORDER BY next_attempt_at
                     LIMIT :limit
                       FOR UPDATE SKIP LOCKED
                ), claimed AS (
                    UPDATE webhook_deliveries d
                       SET attempt = d.attempt + 1,
                           next_attempt_at = now() + make_interval(secs => :lease)
                      FROM due
                     WHERE d.id = due.id
                 RETURNING d.*
                )
                SELECT d.id, d.product_id, p.code AS product_code, d.event_id, d.event_type, d.tenant_id, d.payload,
                       d.occurred_at, d.attempt, d.next_attempt_at, d.delivered_at, d.parked_at, d.last_status, d.last_error, d.created_at
                  FROM claimed d
                  JOIN products p ON p.id = d.product_id
                 ORDER BY d.occurred_at, d.created_at
                SQL,
            ['limit' => max(1, min($limit, 500)), 'lease' => $leaseSeconds],
        );

        return array_map(self::toDelivery(...), $rows);
    }

    public function recordDelivered(string $deliveryId, int $status): void
    {
        $this->connection->executeStatement(
            'UPDATE webhook_deliveries SET delivered_at = now(), last_status = :status, last_error = NULL WHERE id = :id',
            ['id' => $deliveryId, 'status' => $status],
        );
    }

    public function recordFailure(string $deliveryId, ?int $status, string $error, ?DateTimeImmutable $nextAttemptAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE webhook_deliveries
                   SET last_status = :status,
                       last_error = :error,
                       next_attempt_at = COALESCE(CAST(:next AS timestamptz), next_attempt_at),
                       parked_at = CASE WHEN CAST(:next AS timestamptz) IS NULL THEN now() ELSE NULL END
                 WHERE id = :id
                SQL,
            ['id' => $deliveryId, 'status' => $status, 'error' => substr($error, 0, 200), 'next' => $nextAttemptAt?->format('Y-m-d H:i:sP')],
        );
    }

    public function recent(string $productId, int $limit): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM webhook_deliveries d JOIN products p ON p.id = d.product_id WHERE d.product_id = :product ORDER BY d.created_at DESC, d.id LIMIT :limit',
            ['product' => $productId, 'limit' => max(1, min($limit, 500))],
        );

        return array_map(self::toDelivery(...), $rows);
    }

    public function retry(string $productId, string $deliveryId): ?WebhookDelivery
    {
        if (!Uuid::isValid($productId) || !Uuid::isValid($deliveryId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH retried AS (
                    UPDATE webhook_deliveries
                       SET parked_at = NULL, next_attempt_at = now()
                     WHERE id = :id AND product_id = :product AND delivered_at IS NULL
                 RETURNING *
                )
                SELECT d.id, d.product_id, p.code AS product_code, d.event_id, d.event_type, d.tenant_id, d.payload,
                       d.occurred_at, d.attempt, d.next_attempt_at, d.delivered_at, d.parked_at, d.last_status, d.last_error, d.created_at
                  FROM retried d
                  JOIN products p ON p.id = d.product_id
                SQL,
            ['id' => $deliveryId, 'product' => $productId],
        );

        if ($row !== false) {
            return self::toDelivery($row);
        }

        // Delivered already, or not this product's: told apart by whether
        // the row exists here at all, so a delivered one is answered as it
        // stands rather than as absent.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM webhook_deliveries d JOIN products p ON p.id = d.product_id WHERE d.id = :id AND d.product_id = :product',
            ['id' => $deliveryId, 'product' => $productId],
        );

        return $row === false ? null : self::toDelivery($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toDelivery(array $row): WebhookDelivery
    {
        $payload = $row['payload'] ?? null;
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
        $detail = [];

        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $detail[$key] = $value;
                }
            }
        }

        return new WebhookDelivery(
            Row::string($row, 'id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'product_code'),
            Row::string($row, 'event_id'),
            Row::string($row, 'event_type'),
            Row::nullableString($row, 'tenant_id'),
            $detail,
            Row::timestamp($row, 'occurred_at'),
            Row::integer($row, 'attempt'),
            Row::timestamp($row, 'next_attempt_at'),
            Row::nullableTimestamp($row, 'delivered_at'),
            Row::nullableTimestamp($row, 'parked_at'),
            Row::nullableInteger($row, 'last_status'),
            Row::nullableString($row, 'last_error'),
            Row::timestamp($row, 'created_at'),
        );
    }
}
