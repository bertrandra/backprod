<?php

declare(strict_types=1);

namespace App\Payment\Service;

use RuntimeException;

/**
 * Raised under the invoice's lock when there turns out to be nothing to send
 * back, so the transaction that was about to record a refund unwinds without
 * one. Never leaves {@see Payments::returnUnowed()}.
 */
final class NothingUnowed extends RuntimeException
{
}
