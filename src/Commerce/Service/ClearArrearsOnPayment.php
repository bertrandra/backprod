<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoicePaid;
use App\Commerce\Domain\DunningSchedule;
use App\Commerce\Domain\SubscriptionRepository;
use App\Product\Domain\ProductSettings;

/**
 * An invoice reaching PAID lifts the suspension it caused (2026-09-27,
 * spec §5.1).
 *
 * The other half of `PAST_DUE`. Suspending a workshop is only defensible if
 * paying reopens it, and reopening has to happen wherever the money lands —
 * which is why this hangs off {@see InvoicePaid} and not off the payment
 * webhook. §25 lets an invoice reach PAID two ways, a card confirmed by the
 * provider and a bank transfer an operator reconciles by hand, and a customer
 * who paid by transfer must not stay locked out because the gate was hung off
 * the card.
 *
 * **Keyed on the invoice**, not on the subscription. A subscription suspended
 * by March's invoice is not reopened by April's being settled: the column says
 * which debt shut it, and that is the one that lifts it. Any-payment-reopens
 * would let a €0 correction undo a suspension the customer never paid.
 *
 * **Not while another debt holds it** (ADR-072). Arrears under commitment
 * keep renewing, so a suspended subscription can owe several invoices; paying
 * the one that shut it while another is unpaid past the product's grace hands
 * the suspension to that one instead of reopening — otherwise the next
 * collection pass would shut it again, and the customer would have had the
 * workshop back for a night by paying a third of what they owe. The grace is
 * the product's own ({@see DunningSchedule}), read here as the collection pass
 * reads it.
 *
 * Finding nothing is the ordinary case, not a failure. Most invoices suspend
 * nothing at all — a first sale, a proration paid the same minute, a document
 * raised by hand — and the repository answers false rather than raising.
 *
 * Inside the caller's transaction, like every other `InvoicePaid` listener: a
 * payment collected, an invoice settled and the product still shut is the state
 * this ordering exists to make unobservable.
 */
final class ClearArrearsOnPayment implements InvoicePaid
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly ProductSettings $settings,
    ) {
    }

    public function paid(Invoice $invoice): void
    {
        if ($invoice->subscriptionId === null) {
            // Nothing a subscription was suspended for. Asked here rather than
            // left to the query so that the ordinary case costs nothing.
            return;
        }

        $this->subscriptions->clearArrears(
            $invoice->id,
            DunningSchedule::fromConfiguration($this->settings->all($invoice->productId))->graceDays(),
        );
    }
}
