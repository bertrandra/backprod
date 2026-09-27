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
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null, ?string $person = null, ?string $status = null): array;

    public function countForTenant(string $tenantId, string $productId, ?string $ownedBy = null, ?string $person = null, ?string $status = null): int;

    public function find(string $tenantId, string $productId, string $paymentId, ?string $ownedBy = null): ?Payment;

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
     * The most recent **settled** payment against an invoice of a subscription
     * (2026-09-27).
     *
     * What a proration credit is a share of: the money that actually bought
     * the period now being cut short (spec §3.3). Settled and not merely
     * started, because an authorization nobody collected is not money to give
     * back.
     *
     * Asked through the invoice rather than through `payments.subscription_id`,
     * and the reason is a real one: a seat's first invoice is raised *before*
     * the subscription exists — the money is what starts it — so that payment
     * carries no subscription id and only the invoice is ever linked back. A
     * query on the column would silently miss the very first period of every
     * subscription bought through the sales chain.
     */
    public function latestSettledForSubscription(
        string $tenantId,
        string $productId,
        string $subscriptionId,
    ): ?Payment;

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
     *
     * `$alsoRecord` runs **inside** that transaction, after the refund row
     * exists (2026-09-26) — the shape {@see \App\Billing\Domain\InvoiceRepository::issue}
     * and {@see \App\Billing\Domain\CreditNoteRepository::issue} both have.
     * It is how a refund carries its credit note: money going back and the
     * document that makes it legal commit together or not at all, exactly as
     * an early-termination charge is raised on the cancellation's own
     * transaction. A refund with no credit note leaves the invoice's VAT
     * declared while the money has gone.
     *
     * @param (callable(Refund): void)|null $alsoRecord
     */
    public function recordRefund(
        Payment $payment,
        ProviderRefund $refund,
        Money $amount,
        string $reason,
        ?string $actorUserId,
        ?callable $alsoRecord = null,
    ): Refund;
}
