<?php

declare(strict_types=1);

namespace App\Sales\Domain;

/**
 * What an order causes, in the two moments it now happens across.
 *
 * Until the gate, these were one act: an order was fulfilled and the
 * subscription started whether or not the money ever arrived. That is a
 * decision to extend credit to everyone who can reach the endpoint, taken by
 * default rather than deliberately. Split, the sale reads the way it works:
 * the invoice is raised when the order is fulfilled, and what was bought
 * starts when that invoice is paid.
 *
 * A port rather than a direct call, for the same reason
 * {@see \App\Payment\Domain\PaymentSettlement} is one — the sales repository
 * must not learn what a subscription or an invoice is. It knows only that
 * something has to happen inside its transaction and that whoever supplied
 * this knows what.
 *
 * Both methods are called from inside a transaction the caller already holds,
 * so both must use the *participating* repository methods rather than opening
 * transactions of their own.
 */
interface OrderFulfilment
{
    /**
     * Raises the invoice the order is to be paid against — or none.
     *
     * **Nothing to collect raises no document** (ADR-078). A free offer prices
     * an order at zero: `invoice_id` is null, and
     * the caller completes the order at once rather than parking it behind a
     * payment that would never come. Until 2026-11-27 this raised a €0 invoice
     * anyway — a permanent number in a gapless series for no transaction,
     * which the checkout then tried to collect and the collection pass then
     * suspended the free seat for.
     *
     * @return array{invoice_id: string|null}
     */
    public function invoice(Order $order, ?string $actorUserId = null): array;

    /**
     * Starts what the order bought, now it is paid for.
     *
     * This runs when the money has arrived, which may be days after the order
     * was placed and after the offer has been withdrawn from sale. It
     * therefore activates on the terms the order recorded rather than
     * re-checking what is on sale today: refusing here would strand a
     * customer who has paid.
     *
     * @return string the subscription's id
     */
    public function activate(Order $order): string;
}
