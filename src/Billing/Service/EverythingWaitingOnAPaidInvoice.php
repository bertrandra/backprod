<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoicePaid;

/**
 * More than one thing waits on an invoice being paid, so the port fans out
 * (2026-09-27).
 *
 * `InvoicePaid` had exactly one implementation until today — the order
 * completing — and the container bound the interface straight to it. Arrears
 * (spec §5.1) is the second: a suspended subscription reopens when the invoice
 * that suspended it is paid, and that is a different module's business from the
 * order's.
 *
 * **A composite rather than a second binding**, and rather than one listener
 * calling the other. Chaining them would make Sales know about arrears, or
 * Commerce know about orders, and the port exists precisely so Billing does not
 * have to learn what either is. Here nothing learns anything: this takes a list
 * of the port and Billing still knows only that something happens alongside its
 * own write.
 *
 * **Order is caller order, and it is not arbitrary**: the listeners run in the
 * sequence the container lists them, inside the caller's transaction, and a
 * throw from any of them rolls back the payment and the settled invoice with
 * it. That is the right failure — a payment recorded, an invoice marked paid
 * and a product still shut would be invisible and permanent — and it is why
 * nothing here catches: swallowing one listener's error would produce exactly
 * the half-done state the single transaction is for.
 */
final class EverythingWaitingOnAPaidInvoice implements InvoicePaid
{
    /** @var list<InvoicePaid> */
    private readonly array $listeners;

    /**
     * @param iterable<InvoicePaid> $listeners
     */
    public function __construct(iterable $listeners)
    {
        $all = [];

        foreach ($listeners as $listener) {
            $all[] = $listener;
        }

        $this->listeners = $all;
    }

    public function paid(Invoice $invoice): void
    {
        foreach ($this->listeners as $listener) {
            $listener->paid($invoice);
        }
    }
}
