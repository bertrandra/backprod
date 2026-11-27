<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Checkout\Service\Checkout;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Payment\Domain\Refund;
use App\Payment\Service\Payments;
use App\Product\Domain\ProductSettings;
use App\Sales\Domain\CheckoutPolicy;
use App\Sales\Domain\Order;
use App\Sales\Domain\SalesRepository;
use App\Sales\Service\PurchaseNotices;
use App\Shared\Exceptions\HttpException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Ends the two kinds of order that nothing else ever ended (ADR-079).
 *
 * **A held order** is one whose money arrived for a seat its buyer already
 * held: two checkouts opened before either was paid, the second paid after the
 * first started the subscription. The payment's transaction rightly keeps the
 * money and writes `ORDER_HELD` rather than failing — and from there nothing
 * read that row. The customer's screen promised a refund; nobody made one. This
 * pass makes it: the payment goes back through the ordinary refund chain, which
 * plans the credit note before the provider is asked and writes it on the
 * refund's own transaction (ADR-058), then the order is closed and its buyer
 * told.
 *
 * **An abandoned checkout** is an order still waiting for a payment nobody is
 * making — a closed tab. Its invoice stayed issued and its VAT stayed in the
 * organisation's return, indefinitely, for a sale that never happened. Past the
 * delay the product chose ({@see CheckoutPolicy}) it is given up exactly as the
 * buyer would have done it, through the same service: the invoice is cancelled
 * with the reversal of the VAT it declared, then the order.
 *
 * **Never while money may still arrive.** A checkout whose last attempt is
 * still open — a card being authenticated, a debit in flight — is left for the
 * next pass. Giving it up would cancel the document an in-flight payment is
 * about to settle.
 *
 * Safe to run twice: a closed order is no longer read, a refund already made is
 * recognised by the refund chain's own refusal, and a checkout given up is no
 * longer waiting.
 */
final class SettleStrandedOrders implements JobHandler
{
    public const TYPE = 'sweep.orders';

    private const BATCH = 100;

    /** Batches of abandoned checkouts one pass reads; bounded because a pass has to finish. */
    private const PAGES = 10;

    public function __construct(
        private readonly SalesRepository $sales,
        private readonly Payments $payments,
        private readonly Checkout $checkout,
        private readonly PurchaseNotices $notices,
        private readonly ProductSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Job $job): array
    {
        return $this->refundHeld() + $this->giveUpAbandoned();
    }

    /**
     * @return array{refunded: int, refund_failed: int}
     */
    private function refundHeld(): array
    {
        $refunded = 0;
        $failed = 0;

        foreach ($this->sales->heldOrders(self::BATCH) as $order) {
            try {
                $this->giveBack($order);

                if ($this->sales->closeHeldOrder($order, $this->notices->refunded(...))) {
                    $refunded++;
                }
            } catch (Throwable $error) {
                // One order's provider refusing must not cost the others
                // theirs. The class and never the message (§31); the order is
                // still held, so the next pass asks again.
                $failed++;
                $this->logger->error('A held order could not be refunded', [
                    'order_id' => $order->id,
                    'error' => $error::class,
                ]);
            }
        }

        return ['refunded' => $refunded, 'refund_failed' => $failed];
    }

    /**
     * Everything the order's payment collected, back to where it came from.
     *
     * A refund that finds nothing left to return is the pass that ran before
     * this one and stopped between the refund and the closing: the money has
     * gone, so the order is closed now rather than refunded twice.
     */
    private function giveBack(Order $order): void
    {
        if ($order->invoiceId === null) {
            return;
        }

        $payment = $this->payments->latestFor($order->tenantId, $order->productId, $order->invoiceId);

        if ($payment === null) {
            // Settled by hand — a transfer reconciled by an operator. There is
            // no card to give it back to, and returning a transfer is a
            // decision somebody takes. Left held, and counted as a failure so
            // the pass says so rather than looking clean.
            throw new RuntimeException('A held order has no payment to refund.');
        }

        try {
            $this->payments->refund(
                $order->tenantId,
                $order->productId,
                $payment->id,
                null,
                Refund::DUPLICATE,
                null,
            );
        } catch (HttpException $refusal) {
            if ($refusal->errorCode() !== 'PAYMENT_NOT_REFUNDABLE') {
                throw $refusal;
            }
        }
    }

    /**
     * @return array{abandoned: int, in_flight: int, not_yet: int, abandon_failed: int}
     */
    private function giveUpAbandoned(): array
    {
        $abandoned = 0;
        $inFlight = 0;
        $notYet = 0;
        $failed = 0;

        /** @var array<string, CheckoutPolicy> $policies by product, so one pass reads each product's once */
        $policies = [];

        // The shortest delay any product could have chosen bounds the read;
        // each product's own answer decides.
        //
        // The offset is the number of rows this pass has left behind, not a
        // page number: a checkout given up leaves the read, so counting pages
        // would step over as many rows as were just closed.
        $left = 0;

        for ($page = 0; $page < self::PAGES; $page++) {
            $batch = $this->sales->ordersAwaitingPaymentFor(CheckoutPolicy::SHORTEST, self::BATCH, $left);

            foreach ($batch as $stranded) {
                $order = $stranded->order;
                $policy = $policies[$order->productId]
                    ??= CheckoutPolicy::fromConfiguration($this->settings->all($order->productId));

                if (!$policy->isAbandonedAfter($stranded->hoursWaiting)) {
                    $notYet++;
                    $left++;

                    continue;
                }

                if ($this->moneyMayStillArrive($order)) {
                    $inFlight++;
                    $left++;

                    continue;
                }

                try {
                    // The buyer's own door, with nobody acting: the invoice is
                    // cancelled with its VAT reversal, then the order.
                    $this->checkout->cancel($order->tenantId, $order->productId, $order->id, null);
                    $abandoned++;
                } catch (Throwable $error) {
                    $failed++;
                    $left++;
                    $this->logger->error('An abandoned checkout could not be given up', [
                        'order_id' => $order->id,
                        'error' => $error::class,
                    ]);
                }
            }

            if (count($batch) < self::BATCH) {
                break;
            }
        }

        return ['abandoned' => $abandoned, 'in_flight' => $inFlight, 'not_yet' => $notYet, 'abandon_failed' => $failed];
    }

    /** Whether the checkout's last attempt is still open, or has collected. */
    private function moneyMayStillArrive(Order $order): bool
    {
        if ($order->invoiceId === null) {
            return false;
        }

        $latest = $this->payments->latestFor($order->tenantId, $order->productId, $order->invoiceId);

        return $latest !== null && (!$latest->isFinal() || $latest->isSettled());
    }
}
