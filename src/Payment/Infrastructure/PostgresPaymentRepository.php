<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Billing\Domain\Money;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentSettlement;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\ProviderRefund;
use App\Payment\Domain\Refund;
use App\Payment\Domain\WebhookOutcome;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use stdClass;

/**
 * Payments in PostgreSQL, and the one transaction that makes a webhook
 * delivery exactly-once.
 *
 * {@see self::apply()} is the milestone's exit criterion. It inserts the
 * delivery and does the work it asks for inside a single transaction, with
 * the insert first, so that a replay hits `payment_events_delivered_once`
 * before anything beside it can happen twice. There is no "have I seen this
 * before?" check anywhere, because a check is a race: two deliveries of the
 * same event arriving together would both find nothing and both proceed. The
 * index cannot be raced.
 */
final class PostgresPaymentRepository implements PaymentRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, invoice_id, subscription_id, provider,
        provider_payment_id, status, amount_minor_units, currency, method,
        failure_code, failure_reason, succeeded_at, failed_at, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE tenant_id = :tenantId AND product_id = :productId
                ORDER BY created_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toPayment(...), $rows);
    }

    public function countForTenant(string $tenantId, string $productId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM payments WHERE tenant_id = :tenantId AND product_id = :productId',
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId, string $productId, string $paymentId): ?Payment
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($paymentId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $paymentId, 'tenantId' => $tenantId, 'productId' => $productId],
        );

        return $row === false ? null : self::toPayment($row);
    }

    public function latestForInvoice(string $tenantId, string $productId, string $invoiceId): ?Payment
    {
        // Ordered by creation, not by status: a retry is a later row, and the
        // question this answers is "what happened last", not "did anything
        // work". The id breaks a tie two attempts in the same instant would
        // otherwise leave unordered.
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($invoiceId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE tenant_id = :tenantId
                  AND product_id = :productId
                  AND invoice_id = :invoiceId
                ORDER BY created_at DESC, id DESC
                LIMIT 1
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'invoiceId' => $invoiceId],
        );

        return $row === false ? null : self::toPayment($row);
    }

    public function findByReference(string $provider, string $providerPaymentId): ?Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE provider = :provider AND provider_payment_id = :reference
                SQL,
            ['provider' => $provider, 'reference' => $providerPaymentId],
        );

        return $row === false ? null : self::toPayment($row);
    }

    public function refundsOf(string $paymentId): array
    {
        if (!Uuid::isValid($paymentId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, payment_id, provider, provider_refund_id, amount_minor_units,
                       currency, reason, status, settled_at, created_at
                  FROM refunds
                 WHERE payment_id = :payment
                 ORDER BY created_at, id
                SQL,
            ['payment' => $paymentId],
        );

        return array_map(self::toRefund(...), $rows);
    }

    public function start(
        string $tenantId,
        string $productId,
        string $invoiceId,
        ?string $subscriptionId,
        string $provider,
        ProviderPayment $started,
        Money $amount,
        ?string $actorUserId,
    ): Payment {
        return $this->connection->transactional(function () use (
            $tenantId,
            $productId,
            $invoiceId,
            $subscriptionId,
            $provider,
            $started,
            $amount,
            $actorUserId,
        ): Payment {
            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO payments
                        (tenant_id, product_id, invoice_id, subscription_id, provider,
                         provider_payment_id, status, amount_minor_units, currency, method)
                    VALUES (:tenantId, :productId, :invoice, :subscription, :provider,
                            :reference, :status, :amount, :currency, :method)
                    RETURNING id
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'productId' => $productId,
                    'invoice' => $invoiceId,
                    'subscription' => $subscriptionId,
                    'provider' => $provider,
                    'reference' => $started->id,
                    'status' => $started->status,
                    'amount' => $amount->minorUnits,
                    'currency' => $amount->currency,
                    'method' => $started->method,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to start a payment.');
            }

            $this->record(
                $tenantId,
                $productId,
                'PAYMENT_INITIATED',
                $id,
                $invoiceId,
                $subscriptionId,
                $amount,
                $actorUserId,
                ['provider' => $provider],
            );

            return $this->requireById($id);
        });
    }

    public function apply(
        ProviderEvent $event,
        string $provider,
        ?Payment $payment,
        PaymentSettlement $settlement,
    ): WebhookOutcome {
        // Decided before the transaction opens, so the transaction contains
        // only the record and the work — nothing that could fail for a
        // reason unrelated to either.
        $outcome = self::decide($event, $payment);

        try {
            return $this->connection->transactional(
                fn (): WebhookOutcome => $this->recordThenApply($event, $provider, $payment, $outcome, $settlement),
            );
        } catch (UniqueConstraintViolationException) {
            // The delivery is already recorded, so it has already been acted
            // on and the transaction rolled back without touching anything.
            // This is the ordinary case of a provider retrying, not a fault:
            // answering with an error would make it retry forever.
            return WebhookOutcome::of(WebhookOutcome::DUPLICATE, $payment?->id);
        }
    }

    public function recordRefund(
        Payment $payment,
        ProviderRefund $refund,
        Money $amount,
        string $reason,
        ?string $actorUserId,
    ): Refund {
        return $this->connection->transactional(function () use (
            $payment,
            $refund,
            $amount,
            $reason,
            $actorUserId,
        ): Refund {
            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO refunds
                        (payment_id, tenant_id, product_id, provider, provider_refund_id,
                         amount_minor_units, currency, reason, status)
                    VALUES (:payment, :tenantId, :productId, :provider, :reference,
                            :amount, :currency, :reason, :status)
                    RETURNING id
                    SQL,
                [
                    'payment' => $payment->id,
                    'tenantId' => $payment->tenantId,
                    'productId' => $payment->productId,
                    'provider' => $payment->provider,
                    'reference' => $refund->id,
                    'amount' => $amount->minorUnits,
                    'currency' => $amount->currency,
                    'reason' => $reason,
                    'status' => $refund->status,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to record a refund.');
            }

            $this->record(
                $payment->tenantId,
                $payment->productId,
                'PAYMENT_REFUNDED',
                $payment->id,
                $payment->invoiceId,
                $payment->subscriptionId,
                $amount,
                $actorUserId,
                ['reason' => $reason],
            );

            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, payment_id, provider, provider_refund_id, amount_minor_units,
                           currency, reason, status, settled_at, created_at
                      FROM refunds WHERE id = :id
                    SQL,
                ['id' => $id],
            );

            $row = $rows[0] ?? null;

            if ($row === null) {
                throw new RuntimeException('The refund vanished during the transaction that created it.');
            }

            return self::toRefund($row);
        });
    }

    /**
     * What this delivery should do, given what the payment already is.
     *
     * Every "no" is a named outcome rather than a discard, because an
     * operator staring at a payment that did not activate needs to know
     * whether the delivery never arrived, arrived for something unknown, or
     * arrived too late to matter.
     */
    private static function decide(ProviderEvent $event, ?Payment $payment): string
    {
        if ($payment === null) {
            return WebhookOutcome::IGNORED_UNKNOWN_PAYMENT;
        }

        $requested = $event->requestedStatus();

        if ($requested === null) {
            // A refund settling, say: real, recorded, but not a move of the
            // payment itself.
            return $event->type === ProviderEvent::REFUND_SUCCEEDED
                ? WebhookOutcome::APPLIED
                : WebhookOutcome::IGNORED_NOT_APPLICABLE;
        }

        // The one-way machine is what makes a delayed delivery harmless: a
        // retried `failed` arriving after the `succeeded` that superseded it
        // asks for a move that is not permitted, and is recorded as stale
        // rather than reversing a collected payment.
        return $payment->permits($requested) ? WebhookOutcome::APPLIED : WebhookOutcome::IGNORED_STALE;
    }

    private function recordThenApply(
        ProviderEvent $event,
        string $provider,
        ?Payment $payment,
        string $outcome,
        PaymentSettlement $settlement,
    ): WebhookOutcome {
        // First, so a replay is refused here rather than after the work.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO payment_events
                    (payment_id, provider, provider_event_id, provider_payment_id,
                     type, outcome, payload, occurred_at)
                VALUES (:payment, :provider, :event, :reference, :type, :outcome,
                        CAST(:payload AS jsonb), :occurredAt)
                SQL,
            [
                'payment' => $payment?->id,
                'provider' => $provider,
                'event' => $event->id,
                'reference' => $event->providerPaymentId,
                'type' => $event->type,
                'outcome' => $outcome,
                'payload' => self::encode($event->payload),
                'occurredAt' => $event->occurredAt?->format('Y-m-d H:i:s.uP'),
            ],
        );

        if ($outcome !== WebhookOutcome::APPLIED || $payment === null) {
            return WebhookOutcome::of($outcome, $payment?->id);
        }

        if ($event->type === ProviderEvent::REFUND_SUCCEEDED) {
            $this->settleRefund($event, $payment);

            return WebhookOutcome::of(WebhookOutcome::APPLIED, $payment->id);
        }

        $status = $event->requestedStatus();

        if ($status === null) {
            return WebhookOutcome::of(WebhookOutcome::IGNORED_NOT_APPLICABLE, $payment->id);
        }

        $this->moveTo($payment, $status, $event);

        // Inside this transaction, so a collected payment and the invoice it
        // settled can never be observed apart — not even after a crash
        // between them.
        if ($status === PaymentStatus::SUCCEEDED) {
            $settlement->settle($payment);
        }

        return WebhookOutcome::of(WebhookOutcome::APPLIED, $payment->id);
    }

    private function moveTo(Payment $payment, string $status, ProviderEvent $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE payments
                   SET status = :status,
                       succeeded_at = CASE WHEN :status = 'SUCCEEDED' THEN now() ELSE succeeded_at END,
                       failed_at = CASE WHEN :status IN ('FAILED', 'CANCELLED') THEN now() ELSE failed_at END,
                       failure_code = coalesce(:failureCode, failure_code),
                       failure_reason = coalesce(:failureReason, failure_reason),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            [
                'status' => $status,
                // A failed payment must carry a reason — the schema insists —
                // so an adapter that gave none gets a truthful placeholder
                // rather than a constraint violation the customer sees as a
                // 500.
                'failureCode' => $status === PaymentStatus::FAILED
                    ? ($event->failureCode ?? 'UNSPECIFIED')
                    : $event->failureCode,
                'failureReason' => $event->failureReason,
                'id' => $payment->id,
            ],
        );

        $this->record(
            $payment->tenantId,
            $payment->productId,
            self::ledgerTypeFor($status),
            $payment->id,
            $payment->invoiceId,
            $payment->subscriptionId,
            $payment->amount,
            null,
            ['from' => $payment->status, 'to' => $status, 'event' => $event->id],
        );
    }

    /**
     * A refund the provider says has settled.
     *
     * The payment becomes REFUNDED or PARTIALLY_REFUNDED according to how
     * much has come back in total — summed from the refund rows rather than
     * from this one event, because refunds arrive one at a time and only the
     * total says whether anything is left.
     */
    private function settleRefund(ProviderEvent $event, Payment $payment): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE refunds
                   SET status = 'SUCCEEDED', settled_at = now(), updated_at = now()
                 WHERE payment_id = :payment
                   AND provider_refund_id = coalesce(CAST(:reference AS TEXT), provider_refund_id)
                   AND status = 'PENDING'
                SQL,
            ['payment' => $payment->id, 'reference' => $event->providerRefundId],
        );

        $returned = $this->connection->fetchOne(
            <<<'SQL'
                SELECT coalesce(sum(amount_minor_units), 0)
                  FROM refunds
                 WHERE payment_id = :payment AND status = 'SUCCEEDED'
                SQL,
            ['payment' => $payment->id],
        );

        $total = is_numeric($returned) ? (int) $returned : 0;
        $status = $total >= $payment->amount->minorUnits
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        if (!$payment->permits($status)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE payments SET status = :status, updated_at = now() WHERE id = :id',
            ['status' => $status, 'id' => $payment->id],
        );
    }

    private static function ledgerTypeFor(string $status): string
    {
        return match ($status) {
            PaymentStatus::SUCCEEDED => 'PAYMENT_SUCCEEDED',
            PaymentStatus::FAILED, PaymentStatus::CANCELLED => 'PAYMENT_FAILED',
            PaymentStatus::CHARGEBACK => 'PAYMENT_CHARGEBACK',
            PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED => 'PAYMENT_REFUNDED',
            default => 'PAYMENT_INITIATED',
        };
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(
        string $tenantId,
        string $productId,
        string $type,
        string $paymentId,
        string $invoiceId,
        ?string $subscriptionId,
        Money $amount,
        ?string $actorUserId,
        array $detail,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO financial_events
                    (tenant_id, product_id, type, invoice_id, subscription_id, payment_id,
                     amount_minor_units, currency, actor_user_id, detail)
                VALUES (:tenantId, :productId, :type, :invoice, :subscription, :payment,
                        :amount, :currency, :actor, CAST(:detail AS jsonb))
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'type' => $type,
                'invoice' => $invoiceId,
                'subscription' => $subscriptionId,
                'payment' => $paymentId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency,
                'actor' => $actorUserId,
                'detail' => self::encode($detail),
            ],
        );
    }

    private function requireById(string $id): Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE id = :id',
            ['id' => $id],
        );

        if ($row === false) {
            throw new RuntimeException('The payment vanished during the transaction that created it.');
        }

        return self::toPayment($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toPayment(array $row): Payment
    {
        $currency = Row::string($row, 'currency');

        return new Payment(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'invoice_id'),
            Row::nullableString($row, 'subscription_id'),
            Row::string($row, 'provider'),
            Row::string($row, 'provider_payment_id'),
            Row::string($row, 'status'),
            Money::of(Row::integer($row, 'amount_minor_units'), $currency),
            Row::nullableString($row, 'method'),
            Row::nullableString($row, 'failure_code'),
            Row::nullableString($row, 'failure_reason'),
            Row::nullableTimestamp($row, 'succeeded_at'),
            Row::nullableTimestamp($row, 'failed_at'),
            Row::timestamp($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRefund(array $row): Refund
    {
        $currency = Row::string($row, 'currency');

        return new Refund(
            Row::string($row, 'id'),
            Row::string($row, 'payment_id'),
            Row::string($row, 'provider'),
            Row::nullableString($row, 'provider_refund_id'),
            Money::of(Row::integer($row, 'amount_minor_units'), $currency),
            Row::string($row, 'reason'),
            Row::string($row, 'status'),
            Row::nullableTimestamp($row, 'settled_at'),
            Row::timestamp($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }
}
