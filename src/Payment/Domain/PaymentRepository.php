<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Billing\Domain\Money;

/**
 * Payments, their refunds, and the webhook deliveries that moved them.
 *
 * Reads for a tenant are scoped by tenant and product like everything else.
 * The webhook's lookup is not: a delivery arrives unauthenticated, carrying
 * only the provider's handle, so it is resolved by (provider, reference) and
 * the tenant is read off whatever it finds. That is safe because the handle
 * was minted by the provider and the delivery's signature has already been
 * verified — it is not a client-supplied tenant id (ADR-015).
 */
interface PaymentRepository
{
    /**
     * @return list<Payment>
     */
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array;

    public function countForTenant(string $tenantId, string $productId): int;

    public function find(string $tenantId, string $productId, string $paymentId): ?Payment;

    public function findByReference(string $provider, string $providerPaymentId): ?Payment;

    /**
     * The most recent attempt against one invoice, whatever became of it.
     *
     * "Most recent" rather than "the successful one" on purpose: a caller
     * asking how a checkout is going needs to see the attempt that failed,
     * not be told there is no payment. An invoice never collected has none.
     */
    public function latestForInvoice(string $tenantId, string $productId, string $invoiceId): ?Payment;

    /**
     * How many attempts an invoice has already had, settled or not.
     *
     * Used to build a provider reference that names the attempt rather than
     * the invoice. It is a label, not a lock: two callers racing here both
     * see the same count, and what stops the second attempt existing twice is
     * the unique index on (provider, provider_payment_id), not this number.
     */
    public function attemptsForInvoice(string $invoiceId): int;

    /**
     * @return list<Refund>
     */
    public function refundsOf(string $paymentId): array;

    public function start(
        string $tenantId,
        string $productId,
        string $invoiceId,
        ?string $subscriptionId,
        string $provider,
        ProviderPayment $started,
        Money $amount,
        ?string $actorUserId,
    ): Payment;

    /**
     * Records a webhook delivery and applies whatever it asks for, in one
     * transaction.
     *
     * One transaction is the whole point. The delivery is inserted against a
     * unique (provider, event id), so a replay is refused by the index
     * before any of the work beside it can happen a second time — rather
     * than by a check somebody has to remember to write.
     *
     * Implementations must report a replay as {@see WebhookOutcome::DUPLICATE}
     * rather than raising: a provider retries until it is answered, and an
     * error would keep it retrying forever.
     *
     * $settlement is invoked from inside that same transaction when a
     * payment succeeds, so the money and the document it paid cannot be
     * observed in disagreement.
     */
    public function apply(
        ProviderEvent $event,
        string $provider,
        ?Payment $payment,
        PaymentSettlement $settlement,
    ): WebhookOutcome;

    /**
     * Records a refund this platform asked for. Settlement arrives later, by
     * webhook.
     */
    public function recordRefund(
        Payment $payment,
        ProviderRefund $refund,
        Money $amount,
        string $reason,
        ?string $actorUserId,
    ): Refund;
}
