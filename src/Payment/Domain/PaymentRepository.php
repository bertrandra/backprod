<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Money\Money;

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
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null, ?string $person = null, ?string $status = null, ?string $from = null, ?string $to = null): array;

    public function countForTenant(string $tenantId, string $productId, ?string $ownedBy = null, ?string $person = null, ?string $status = null, ?string $from = null, ?string $to = null): int;

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
     * The most recent **settled** payment of one invoice (ADR-076).
     *
     * What a proration credit is a share of: the money that bought the period
     * being cut (spec §3.3). Settled and not merely started, because an
     * authorization nobody collected is not money to give back.
     *
     * By invoice, and it used to be "of any invoice of the subscription". The
     * period being cut has one invoice; the newest settled payment of the
     * subscription is that invoice's only while every invoice is paid as it is
     * raised, and a renewal invoice is raised days before it is due.
     */
    public function latestSettledForInvoice(string $tenantId, string $productId, string $invoiceId): ?Payment;

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
     * Asks for a refund and records it, **under the invoice's lock**
     * (2026-10-29).
     *
     * The transaction opens and locks the invoice the payment collected
     * (`SELECT … FOR UPDATE`) before anything else, then calls `$underLock`,
     * which re-reads the ceilings, plans the credit note and asks the
     * provider. Everything it read stays true until the refund and its credit
     * note are written, because every other refund of that invoice — and
     * every credit note, {@see \App\Billing\Domain\CreditNoteRepository::applyIssue}
     * takes the same lock — waits behind it. Until today the ceilings were
     * read with no lock, the provider was asked, and the credit-note write
     * could then refuse `CREDIT_EXCEEDS_INVOICE` after the money had left.
     *
     * `$underLock` answers with the provider's refund, the amount asked for,
     * and what to write beside it. The refund row carries the provider's own
     * status: a refund the provider failed at once writes no document and is
     * recorded FAILED; one it settled at once moves the payment as the
     * webhook would have.
     *
     * `alsoRecord` runs **inside** that transaction, after the refund row
     * exists (2026-09-26), and answers the id of the credit note it wrote. It
     * is how a refund carries its credit note: money going back and the
     * document that makes it legal commit together or not at all. A refund
     * with no credit note leaves the invoice's VAT declared while the money
     * has gone.
     *
     * The provider is called with a transaction open. That is the price of
     * the lock, and it is paid by refunds of the same invoice only.
     *
     * **`$onceFor` makes the refund happen once** (ADR-076). It names what
     * the money is being returned *for* — a proration names the subscription
     * and the period it cuts — and a second call with the same name finds the
     * first refund under the lock and answers it, without asking the provider
     * again. `refunds_once_per_purpose` is the constraint behind that; the read
     * under the lock is only what lets the answer be the earlier refund rather
     * than a violation. A refund that FAILED frees the name, because no money
     * moved.
     *
     * @param callable(): array{refund: ProviderRefund, amount: Money, alsoRecord: (callable(Refund): ?string)|null} $underLock
     */
    public function recordRefund(
        Payment $payment,
        string $reason,
        ?string $actorUserId,
        callable $underLock,
        ?string $onceFor = null,
    ): Refund;

    /**
     * Settled payments whose invoice is CANCELLED and whose money has not all
     * gone back (ADR-076), oldest first.
     *
     * Across tenants, like every question a scheduled pass asks: money against
     * a void document is nobody's until it is returned, and the pass that
     * returns it has no tenant of its own.
     *
     * @return list<Payment>
     */
    public function settledAgainstVoidInvoices(int $limit): array;

    /**
     * The attempts against an invoice that can still collect — PENDING or
     * AUTHORIZED (2026-10-29). At most one, since
     * `payments_one_live_attempt_per_invoice`; a list because the question is
     * asked of rows written before that index existed too.
     *
     * @return list<Payment>
     */
    public function liveAttemptsForInvoice(string $invoiceId): array;

    /**
     * Marks an attempt the provider has abandoned as CANCELLED, so a new one
     * may replace it (2026-10-29).
     *
     * Conditioned on the attempt still being live: false when something —
     * a webhook — moved it first, and the caller must not start another.
     */
    public function supersede(Payment $payment, ?string $actorUserId): bool;
}
