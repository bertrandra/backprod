<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Commerce\Domain\Subscription;
use App\Notification\Domain\Category;
use App\Notification\Service\Notifications;
use App\Product\Domain\ProductRepository;
use App\Tenant\Domain\DefaultTenant;
use App\Tenant\Domain\TenantRepository;

/**
 * Asks the customer to pay for the period about to start (2026-10-05).
 *
 * The operator's answer to R11, in their words: *« la demande de paiement est
 * déclenchée à J-7, ou X jours — un paramètre dans la console — avec un e-mail à
 * l'utilisateur et un lien vers le paiement ; il paie alors par carte ou autre
 * moyen. »* The renewal raises the invoice `lead_days` before the period ends
 * ({@see \App\Commerce\Domain\RenewalPolicy}), and this is the mail that carries
 * it: the invoice, the day it is due, and a link straight to the screen it is
 * paid from.
 *
 * **Due when the period starts, not when it is raised.** An invoice raised seven
 * days early and "payable on receipt" would have been chased the next morning
 * and the subscription suspended inside a period already paid for. The due date
 * is the invoice's own (`due_at`), so the collection schedule counts from it
 * and nothing else had to change.
 *
 * **Addressed to whoever can settle it** — the seat's holder and whoever took
 * the subscription out, once each — the same union the overdue chase uses, so
 * the person asked before the date is the person chased after it.
 *
 * **Kept as sent**: it is a demand for payment, and "what did we ask for, and
 * when?" has to be answerable from what went out rather than reconstructed.
 *
 * Once per invoice and recipient, by the notifications' dedup index rather than
 * a check, and on the renewal's own transaction: the period, the invoice and the
 * notice of it commit together, and the sending itself goes through the queue
 * (§27.1) — never inside whatever moved the period.
 */
final class RenewalPaymentRequest
{
    public const TYPE = 'subscription.renewal_payment_request';

    public function __construct(
        private readonly Notifications $notifications,
        private readonly TenantRepository $tenants,
        private readonly DefaultTenant $defaultTenant,
        private readonly ProductRepository $products,
        private readonly string $appUrl = '',
    ) {
    }

    public function ask(Subscription $subscription, Invoice $invoice): void
    {
        $link = $this->linkTo($subscription, $invoice);
        $recipients = array_values(array_unique(array_filter(
            [$subscription->subscriberUserId, $subscription->ownerUserId],
            static fn (?string $id): bool => $id !== null,
        )));

        foreach ($recipients as $recipient) {
            $this->notifications->raise(
                $subscription->tenantId,
                $subscription->productId,
                $recipient,
                self::TYPE,
                Category::BILLING,
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number ?? '',
                    'offer' => $subscription->offer->name,
                    'due_on' => ($invoice->dueAt ?? $invoice->issuedAt)?->format('Y-m-d') ?? '',
                    'period_end' => $invoice->periodEnd?->format('Y-m-d') ?? '',
                    'link' => $link,
                ],
                'renewal-request:' . $invoice->id . ':' . $recipient,
                true,
            );
        }
    }

    /**
     * The invoice's own screen, under the organisation's root and for the
     * product it belongs to — the address a person signed in there would see.
     */
    private function linkTo(Subscription $subscription, Invoice $invoice): string
    {
        $tenant = $this->tenants->find($subscription->tenantId);
        $root = $tenant === null || $this->defaultTenant->id() === $tenant->id ? '' : '/' . $tenant->slug;
        $product = $this->products->find($subscription->productId);
        $query = $product === null ? '' : '?product=' . rawurlencode($product->code);

        return rtrim($this->appUrl, '/') . $root . '/invoices/' . rawurlencode($invoice->id) . $query;
    }
}
