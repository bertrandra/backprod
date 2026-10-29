<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentSettlement;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\Refund;
use App\Payment\Domain\WebhookOutcome;
use App\Shared\Database\DocumentPersonSql;
use App\Shared\Database\DocumentWindowSql;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Money\Money;
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

    private const REFUND_COLUMNS = <<<'SQL'
        id, payment_id, provider, provider_refund_id, amount_minor_units,
        currency, reason, status, settled_at, created_at, credit_note_id
        SQL;

    /**
     * The moment this list is ordered by, and therefore the one a date
     * filter narrows on (2026-09-28). One constant rather than the
     * expression written twice: a page and its total reading different
     * clocks is a disagreement nobody sees until they count.
     */    private const MOMENT = 'created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null, ?string $person = null, ?string $status = null, ?string $from = null, ?string $to = null): array
    {
        $narrow = DocumentPersonSql::narrowing(DocumentPersonSql::ofPayment('payments'));
        $window = DocumentWindowSql::narrowing(self::MOMENT);

        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        // A person's own payments (2026-09-18): those on their seat's invoices.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<SQL
                 FROM payments
                WHERE tenant_id = :tenantId AND product_id = :productId
                  AND {$narrow} AND (CAST(:status AS text) IS NULL OR status = CAST(:status AS text))
                  AND {$window}
                ORDER BY created_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset, 'ownedBy' => $ownedBy, 'person' => $person, 'status' => $status, 'from' => $from, 'to' => $to],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toPayment(...), $rows);
    }

    public function countForTenant(string $tenantId, string $productId, ?string $ownedBy = null, ?string $person = null, ?string $status = null, ?string $from = null, ?string $to = null): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM payments WHERE tenant_id = :tenantId AND product_id = :productId AND ' . DocumentPersonSql::narrowing(DocumentPersonSql::ofPayment('payments')) . ' AND (CAST(:status AS text) IS NULL OR status = CAST(:status AS text)) AND ' . DocumentWindowSql::narrowing(self::MOMENT),
            ['tenantId' => $tenantId, 'productId' => $productId, 'ownedBy' => $ownedBy, 'person' => $person, 'status' => $status, 'from' => $from, 'to' => $to],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId, string $productId, string $paymentId, ?string $ownedBy = null): ?Payment
    {
        $narrow = DocumentPersonSql::narrowing(DocumentPersonSql::ofPayment('payments'));

        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($paymentId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<SQL
                 FROM payments
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                  AND {$narrow}
                SQL,
            ['id' => $paymentId, 'tenantId' => $tenantId, 'productId' => $productId, 'ownedBy' => $ownedBy, 'person' => null],
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

    public function latestSettledForSubscription(
        string $tenantId,
        string $productId,
        string $subscriptionId,
    ): ?Payment {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($subscriptionId)) {
            return null;
        }

        // Through `invoices.subscription_id`, never `payments.subscription_id`
        // — the reason is on the interface, and it is that a seat's first
        // payment predates the subscription it starts.
        //
        // `PARTIALLY_REFUNDED` counts as settled, exactly as
        // {@see PaymentStatus::isSettled()} says: some of that money is still
        // ours, and how much is what the caller goes on to bound the credit
        // by. Excluding it would treat a period one euro of which came back
        // as a period nobody paid for.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE tenant_id = :tenantId
                  AND product_id = :productId
                  AND status IN ('SUCCEEDED', 'PARTIALLY_REFUNDED')
                  AND invoice_id IN (SELECT id FROM invoices WHERE subscription_id = :subscriptionId)
                ORDER BY succeeded_at DESC NULLS LAST, created_at DESC, id DESC
                LIMIT 1
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'subscriptionId' => $subscriptionId],
        );

        return $row === false ? null : self::toPayment($row);
    }

    public function attemptsForInvoice(string $invoiceId): int
    {
        if (!Uuid::isValid($invoiceId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM payments WHERE invoice_id = :invoiceId',
            ['invoiceId' => $invoiceId],
        );

        return is_numeric($count) ? (int) $count : 0;
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
            'SELECT ' . self::REFUND_COLUMNS . <<<'SQL'
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
        try {
            return $this->startOnce($tenantId, $productId, $invoiceId, $subscriptionId, $provider, $started, $amount, $actorUserId);
        } catch (UniqueConstraintViolationException $violation) {
            // Two attempts started at once on one invoice (2026-10-29): the
            // index lets one live attempt exist and refuses the second,
            // rather than a check both would pass.
            if (!str_contains($violation->getMessage(), 'payments_one_live_attempt_per_invoice')) {
                throw $violation;
            }

            throw new ConflictException(
                'PAYMENT_STILL_IN_FLIGHT',
                'Another payment of this invoice has just been started. Wait for it to settle or fail.',
                ['invoice_id' => $invoiceId],
            );
        }
    }

    private function startOnce(
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
        } catch (UniqueConstraintViolationException $violation) {
            // Only the delivered-once index means "already recorded". Any
            // other unique index reached inside the same transaction — a
            // second ACTIVE subscription on the tenant and product, say — is
            // the work failing, and the transaction rolled back *including*
            // the event row. Answering DUPLICATE there would mean the
            // provider collected the money, this platform recorded nothing,
            // every retry answered the same, and nobody was told (ADR-048's
            // first sandbox run found exactly that). It propagates instead:
            // a 500 with a request id, the provider retries, and the log
            // carries the constraint that refused.
            if (!str_contains($violation->getMessage(), 'payment_events_delivered_once')) {
                throw $violation;
            }

            // The delivery is already recorded, so it has already been acted
            // on and the transaction rolled back without touching anything.
            // This is the ordinary case of a provider retrying, not a fault:
            // answering with an error would make it retry forever.
            return WebhookOutcome::of(WebhookOutcome::DUPLICATE, $payment?->id);
        }
    }

    public function recordRefund(
        Payment $payment,
        string $reason,
        ?string $actorUserId,
        callable $underLock,
    ): Refund {
        return $this->connection->transactional(function () use (
            $payment,
            $reason,
            $actorUserId,
            $underLock,
        ): Refund {
            // First, before anything is read: every refund and every credit
            // note of this invoice queues here, so the ceilings `$underLock`
            // reads cannot move before the write below (2026-10-29).
            $this->connection->fetchOne(
                'SELECT id FROM invoices WHERE id = :invoice FOR UPDATE',
                ['invoice' => $payment->invoiceId],
            );

            $asked = $underLock();
            $refund = $asked['refund'];
            $amount = $asked['amount'];

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO refunds
                        (payment_id, tenant_id, product_id, provider, provider_refund_id,
                         amount_minor_units, currency, reason, status, settled_at)
                    VALUES (:payment, :tenantId, :productId, :provider, :reference,
                            :amount, :currency, :reason, :status,
                            CASE WHEN :status = 'SUCCEEDED' THEN now() END)
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

            $failed = $refund->status === Refund::FAILED;

            $this->record(
                $payment->tenantId,
                $payment->productId,
                // Refused by the provider on the spot: no money moved, so no
                // refund in the ledger and no document below — only the fact
                // that one was asked for and refused.
                $failed ? 'REFUND_FAILED' : 'PAYMENT_REFUNDED',
                $payment->id,
                $payment->invoiceId,
                $payment->subscriptionId,
                $amount,
                $actorUserId,
                ['reason' => $reason, 'refund_id' => $id],
            );

            // Last, and still inside: the credit note this refund carries
            // (2026-09-26). Money back with no document leaves the invoice's
            // VAT declared on a sale that was undone, and the period close
            // would freeze it. Linked to the refund (2026-10-29), so a refund
            // that fails later names the document it leaves unanswered.
            $alsoRecord = $asked['alsoRecord'];

            if ($alsoRecord !== null && !$failed) {
                $note = $alsoRecord($this->requireRefund($id));

                if ($note !== null) {
                    $this->connection->executeStatement(
                        'UPDATE refunds SET credit_note_id = :note, updated_at = now() WHERE id = :id',
                        ['note' => $note, 'id' => $id],
                    );
                }
            }

            // Settled on the spot, as a card refund usually is: the payment
            // moves now, exactly as the webhook would have moved it.
            if ($refund->status === Refund::SUCCEEDED) {
                $this->afterRefundSettled($payment->id);
            }

            return $this->requireRefund($id);
        });
    }

    public function liveAttemptsForInvoice(string $invoiceId): array
    {
        if (!Uuid::isValid($invoiceId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM payments
                WHERE invoice_id = :invoiceId AND status IN ('PENDING', 'AUTHORIZED')
                ORDER BY created_at, id
                SQL,
            ['invoiceId' => $invoiceId],
        );

        return array_map(self::toPayment(...), $rows);
    }

    public function supersede(Payment $payment, ?string $actorUserId): bool
    {
        return $this->connection->transactional(function () use ($payment, $actorUserId): bool {
            // Conditioned on the status, never on the id alone: a success
            // that landed since the read is money collected, and overwriting
            // it with CANCELLED would lose it.
            $moved = $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE payments
                       SET status = 'CANCELLED',
                           failed_at = now(),
                           failure_code = 'SUPERSEDED',
                           failure_reason = 'Abandoned for a new attempt at the same invoice.',
                           updated_at = now()
                     WHERE id = :id AND status IN ('PENDING', 'AUTHORIZED')
                    SQL,
                ['id' => $payment->id],
            );

            if ($moved === 0) {
                return false;
            }

            $this->record(
                $payment->tenantId,
                $payment->productId,
                'PAYMENT_FAILED',
                $payment->id,
                $payment->invoiceId,
                $payment->subscriptionId,
                $payment->amount,
                $actorUserId,
                ['from' => $payment->status, 'to' => PaymentStatus::CANCELLED, 'superseded' => true],
            );

            return true;
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
            // A refund settling or failing, or the provider naming the
            // instrument: real, recorded, applied — but not a move of the
            // payment itself.
            return match ($event->type) {
                ProviderEvent::REFUND_SUCCEEDED, ProviderEvent::REFUND_FAILED => WebhookOutcome::APPLIED,
                ProviderEvent::INSTRUMENT_KNOWN => $event->method === null
                    ? WebhookOutcome::IGNORED_NOT_APPLICABLE
                    : WebhookOutcome::APPLIED,
                default => WebhookOutcome::IGNORED_NOT_APPLICABLE,
            };
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

        if ($event->type === ProviderEvent::REFUND_SUCCEEDED || $event->type === ProviderEvent::REFUND_FAILED) {
            $matched = $event->type === ProviderEvent::REFUND_SUCCEEDED
                ? $this->settleRefund($event, $payment)
                : $this->failRefund($event, $payment);

            if ($matched) {
                return WebhookOutcome::of(WebhookOutcome::APPLIED, $payment->id);
            }

            // About no refund this platform has a pending row for — one
            // already settled by the provider's own answer, or one whose row
            // has not committed yet (2026-10-29). Nothing moved, and the
            // delivery says so rather than claiming to have applied.
            $this->connection->executeStatement(
                'UPDATE payment_events SET outcome = :outcome WHERE provider = :provider AND provider_event_id = :event',
                ['outcome' => WebhookOutcome::IGNORED_NOT_APPLICABLE, 'provider' => $provider, 'event' => $event->id],
            );

            return WebhookOutcome::of(WebhookOutcome::IGNORED_NOT_APPLICABLE, $payment->id);
        }

        if ($event->type === ProviderEvent::INSTRUMENT_KNOWN) {
            $this->learnInstrument($payment, $event);

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

    /**
     * The kind of instrument, whichever delivery brought it first.
     *
     * Whatever the payment's status: the delivery that names the instrument
     * and the one that reports the outcome arrive in no guaranteed order,
     * and a method learned before the money moved is as true as one learned
     * after. Never overwritten — a provider that said at `authorize()` time
     * is not corrected by a later word — and never a ledger row, because
     * nothing about the money changed.
     */
    private function learnInstrument(Payment $payment, ProviderEvent $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE payments
                   SET method = coalesce(method, :method),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['method' => $event->method, 'id' => $payment->id],
        );
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
                       method = coalesce(method, :method),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            [
                'status' => $status,
                // The kind of instrument, when the event is the first to know
                // it; whatever was already known — at `authorize()`, or from
                // an INSTRUMENT_KNOWN delivery that landed first — stands.
                'method' => $event->method,
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
     *
     * **Nothing when no row matched** (2026-10-29). The payment's status was
     * recomputed regardless, so a delivery arriving before the refund row had
     * committed — or one about a refund already settled — still moved the
     * payment, on the strength of a refund this platform had not recorded.
     */
    private function settleRefund(ProviderEvent $event, Payment $payment): bool
    {
        $settled = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE refunds
                   SET status = 'SUCCEEDED', settled_at = now(), updated_at = now()
                 WHERE payment_id = :payment
                   AND provider_refund_id = coalesce(CAST(:reference AS TEXT), provider_refund_id)
                   AND status = 'PENDING'
                SQL,
            ['payment' => $payment->id, 'reference' => $event->providerRefundId],
        );

        if ($settled === 0) {
            return false;
        }

        $this->afterRefundSettled($payment->id);

        return true;
    }

    /**
     * A refund the provider says has failed (2026-10-29).
     *
     * Until today nothing could say so: the refund stayed PENDING for ever,
     * went on counting against `REFUND_EXCEEDS_PAYMENT`, and the credit note
     * written with it stayed declared on money that never moved.
     *
     * The refund becomes FAILED, which stops it counting against what is
     * still refundable. The payment does not move — a pending refund never
     * moved it. **The credit note stays**: it is a numbered legal document
     * and is never deleted, and the two remedies — give the money back
     * another way, or invoice the sale again — are a person's decision, not
     * something a webhook should invent. So the failure is put in the ledger
     * with the document it leaves unanswered, `decision_required` set, and
     * the refund keeps its link to it ({@see Refund::needsADecision()}).
     *
     * **After it succeeded, too** (2026-10-29). Stripe can report a refund
     * `failed` or `canceled` after it had answered `succeeded` — the bank
     * returned the money. The refund becomes FAILED the same way, and this
     * time the payment *had* moved for it, so its status is computed again
     * from the refunds still standing ({@see self::afterRefundReversed()});
     * the ledger row says which status it left and which it reached, and the
     * credit note is flagged for a decision exactly as above.
     *
     * Only by the provider's refund id: a failure that named no refund would
     * otherwise fail every pending refund on the payment.
     */
    private function failRefund(ProviderEvent $event, Payment $payment): bool
    {
        if ($event->providerRefundId === null) {
            return false;
        }

        // The status it had is read under the row's lock, in the same
        // statement that moves it: two deliveries about one refund cannot both
        // see SUCCEEDED and both reverse it.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH was AS (
                    SELECT id, status
                      FROM refunds
                     WHERE payment_id = :payment
                       AND provider_refund_id = :reference
                       AND status IN ('PENDING', 'SUCCEEDED')
                     FOR UPDATE
                )
                UPDATE refunds r
                   SET status = 'FAILED', updated_at = now()
                  FROM was
                 WHERE r.id = was.id
                RETURNING r.id, r.credit_note_id, r.amount_minor_units, r.currency,
                          was.status AS previous_status
                SQL,
            ['payment' => $payment->id, 'reference' => $event->providerRefundId],
        );

        if ($row === false) {
            return false;
        }

        $note = Row::nullableString($row, 'credit_note_id');
        $previous = Row::string($row, 'previous_status');

        // A pending refund never moved the payment; a settled one did, and
        // its money is back with the customer's bank no longer.
        $moved = $previous === Refund::SUCCEEDED ? $this->afterRefundReversed($payment->id) : null;

        $this->record(
            $payment->tenantId,
            $payment->productId,
            'REFUND_FAILED',
            $payment->id,
            $payment->invoiceId,
            $payment->subscriptionId,
            Money::of(Row::integer($row, 'amount_minor_units'), Row::string($row, 'currency')),
            null,
            [
                'refund_id' => Row::string($row, 'id'),
                'event' => $event->id,
                'failure_code' => $event->failureCode,
                'credit_note_id' => $note,
                'decision_required' => $note !== null,
                'refund_was' => $previous,
            ] + ($moved === null ? [] : ['from' => $moved[0], 'to' => $moved[1]]),
        );

        return true;
    }

    /**
     * The payment's status once a settled refund has been reversed, from the
     * refunds still standing (2026-10-29). Returns `[from, to]` when it moved.
     *
     * **The one move back the machine allows, and why it is not a move.**
     * {@see PaymentStatus} is one-way so that a stale delivery cannot reverse
     * a collected payment. REFUNDED and PARTIALLY_REFUNDED are not events of
     * the payment, though: they are a sum of its refunds, and the provider has
     * just said one of those never happened. The status is that sum again —
     * SUCCEEDED when nothing settled is left, PARTIALLY_REFUNDED when some
     * is, REFUNDED when the rest still covers it — and nothing else moves: a
     * chargeback, or any status that is not a refund's, is left as it is.
     *
     * @return array{string, string}|null
     */
    private function afterRefundReversed(string $paymentId): ?array
    {
        $payment = $this->requireById($paymentId);

        if ($payment->status !== PaymentStatus::REFUNDED && $payment->status !== PaymentStatus::PARTIALLY_REFUNDED) {
            return null;
        }

        $returned = $this->connection->fetchOne(
            <<<'SQL'
                SELECT coalesce(sum(amount_minor_units), 0)
                  FROM refunds
                 WHERE payment_id = :payment AND status = 'SUCCEEDED'
                SQL,
            ['payment' => $payment->id],
        );

        $total = is_numeric($returned) ? (int) $returned : 0;
        $status = match (true) {
            $total <= 0 => PaymentStatus::SUCCEEDED,
            $total >= $payment->amount->minorUnits => PaymentStatus::REFUNDED,
            default => PaymentStatus::PARTIALLY_REFUNDED,
        };

        if ($status === $payment->status) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE payments SET status = :status, updated_at = now() WHERE id = :id AND status = :from',
            ['status' => $status, 'id' => $payment->id, 'from' => $payment->status],
        );

        return [$payment->status, $status];
    }

    /**
     * The payment's status from the refunds that have actually settled,
     * read again rather than taken from whatever the caller held.
     */
    private function afterRefundSettled(string $paymentId): void
    {
        $payment = $this->requireById($paymentId);

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
            'UPDATE payments SET status = :status, updated_at = now() WHERE id = :id AND status = :from',
            ['status' => $status, 'id' => $payment->id, 'from' => $payment->status],
        );
    }

    private function requireRefund(string $id): Refund
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::REFUND_COLUMNS . ' FROM refunds WHERE id = :id',
            ['id' => $id],
        );

        if ($row === false) {
            throw new RuntimeException('The refund vanished during the transaction that created it.');
        }

        return self::toRefund($row);
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
            Row::nullableString($row, 'credit_note_id'),
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
