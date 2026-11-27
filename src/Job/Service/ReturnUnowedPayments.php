<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Payment\Service\Payments;
use Throwable;

/**
 * Sends back money that settled against a cancelled invoice (ADR-076).
 *
 * {@see \App\Payment\Service\InvoiceSettlement} leaves a CANCELLED invoice
 * alone when a payment lands on it, and says why: the money genuinely arrived
 * and pretending otherwise would lose it. What it left behind was "an anomaly
 * an operator should see" — with no screen that showed it and nobody told.
 * A customer charged for a purchase they had cancelled is not an anomaly to
 * look at; it is their money, and it goes back without anybody having to
 * notice.
 *
 * The door this came through is shut where it can be: cancelling a checkout
 * now abandons its live attempt at the provider first. This pass is for what
 * that cannot reach — an invoice voided by another path while an attempt was
 * live, a provider that confirmed a cancel and collected anyway.
 *
 * Idempotent by what it reads: a payment whose money has gone back, or is on
 * its way, is not in the list, and the refund itself is decided again under
 * the invoice's lock. One failure does not stop the pass — the next payment is
 * somebody else's money.
 */
final class ReturnUnowedPayments implements JobHandler
{
    public const TYPE = 'payment.return_unowed';

    /** A bounded pass: what is left is still there in an hour. */
    private const BATCH = 100;

    public function __construct(private readonly Payments $payments)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        $returned = 0;
        $failed = 0;

        foreach ($this->payments->unowed(self::BATCH) as $payment) {
            try {
                if ($this->payments->returnUnowed($payment) !== null) {
                    $returned++;
                }
            } catch (Throwable) {
                // The provider unreachable, or refusing this one. Counted and
                // left: it is still in the list on the next pass.
                $failed++;
            }
        }

        return ['returned' => $returned, 'failed' => $failed];
    }
}
