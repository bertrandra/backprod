<?php

declare(strict_types=1);

namespace App\Demo\Service;

use App\Billing\Domain\Invoice;
use App\Demo\Domain\DemoWorld;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentSettlement;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Shared\Exceptions\ConflictException;
use DateTimeImmutable;

/**
 * Money arriving in the demonstration world (2026-09-26).
 *
 * The demo seeded five invoices and **no payment at all**, so `/payments`
 * was a permanently empty screen in the one place the platform is shown to
 * people — and a screen that is always empty reads as a feature that does
 * not work. It was extended the same day to name the invoice's legal number
 * and the customer it was raised to, behaviour covered by tests and visible
 * to nobody.
 *
 * **It plays the provider, and nothing else.** A payment is an attempt plus
 * what a provider said became of it, and the demonstration has no provider:
 * calling {@see \App\Payment\Service\Payments::start} would authorize
 * against whichever one the deployment configured — Stripe, creating real
 * payment intents while seeding a demo, or none at all, which would refuse
 * to seed. So this mints the handle an adapter would have handed back and
 * the delivery an adapter would have normalised, and hands both to the
 * repository. Everything below that seam is the real path: the amount comes
 * off the invoice, the status machine is the real one, the delivery is
 * recorded in `payment_events` exactly once, the ledger rows are written,
 * and a success settles its invoice — which is what completes the order and
 * starts the seat.
 *
 * **The amount is never named.** It is the invoice's gross, taken from the
 * document, exactly as `Payments::start` takes it: a demonstration whose
 * payment disagreed with the invoice it collects is worse than an empty
 * screen, because somebody would notice it on stage.
 */
final class DemoCollection
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentSettlement $settlement,
    ) {
    }

    /**
     * An attempt that went through: the invoice is settled by it, in the
     * transaction that records the delivery.
     */
    public function collected(Invoice $invoice, ?string $buyer): Payment
    {
        return $this->apply($this->attempt($invoice, $buyer), ProviderEvent::PAYMENT_SUCCEEDED, null, null);
    }

    /**
     * An attempt the provider refused. The invoice stays owed, the order
     * stays awaiting its money, and the payment keeps its reason for ever.
     */
    public function declined(Invoice $invoice, ?string $buyer, string $code, string $reason): Payment
    {
        return $this->apply($this->attempt($invoice, $buyer), ProviderEvent::PAYMENT_FAILED, $code, $reason);
    }

    /**
     * The row a provider's `authorize()` would have produced.
     *
     * The handle is minted from the invoice and the attempt number, which is
     * what {@see \App\Payment\Infrastructure\StubPaymentProvider} does with
     * the key it is handed — the invoice's *number* repeats across issuers
     * and its id does not, and two attempts on one invoice must not collide
     * on `payments_provider_reference_unique`.
     */
    private function attempt(Invoice $invoice, ?string $buyer): Payment
    {
        $key = sprintf('%s/%d', $invoice->id, $this->payments->attemptsForInvoice($invoice->id) + 1);

        return $this->payments->start(
            $invoice->tenantId,
            $invoice->productId,
            $invoice->id,
            $invoice->subscriptionId,
            DemoWorld::PAYMENT_PROVIDER,
            new ProviderPayment(
                'stub_pi_' . substr(hash('sha256', $key), 0, 24),
                PaymentStatus::PENDING,
                // Never stored, and there is nobody to hand one to here.
                null,
                null,
            ),
            $invoice->gross,
            $buyer,
        );
    }

    /**
     * The delivery, through the same seam the webhook uses once it has
     * verified a signature and normalised a body.
     *
     * Anything but APPLIED is a refusal to seed rather than a row nobody
     * looks at: a demonstration whose payment silently did not apply is an
     * invoice that stayed owed and a seat that never started, discovered on
     * a screen.
     *
     * What comes back is the payment **re-read**, because the delivery is
     * what gave it its status, its moment and its reason — returning the row
     * as it was started would hand every caller a PENDING payment to check.
     */
    private function apply(Payment $payment, string $type, ?string $failureCode, ?string $failureReason): Payment
    {
        $event = new ProviderEvent(
            'stub_evt_' . substr(hash('sha256', $payment->providerPaymentId . ':' . $type), 0, 24),
            $type,
            $payment->providerPaymentId,
            new DateTimeImmutable(),
            $failureCode,
            $failureReason,
            $payment->amount->minorUnits,
            null,
            ['id' => $payment->providerPaymentId, 'type' => $type],
            // What paid, as a label. §24 keeps the instrument itself outside
            // PostgreSQL entirely, and this column holds neither.
            'CARD',
        );

        $outcome = $this->payments->apply($event, DemoWorld::PAYMENT_PROVIDER, $payment, $this->settlement);

        if (!$outcome->changedSomething()) {
            throw new ConflictException(
                'DEMO_PAYMENT_WAS_NOT_APPLIED',
                'A demonstration payment was recorded and did nothing, which no fresh attempt can do.',
                ['payment' => $payment->id, 'type' => $type, 'outcome' => $outcome->outcome],
            );
        }

        return $this->payments->find($payment->tenantId, $payment->productId, $payment->id)
            ?? throw new ConflictException(
                'DEMO_PAYMENT_VANISHED',
                'A demonstration payment disappeared between being recorded and being read back.',
                ['payment' => $payment->id],
            );
    }
}
