<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Payment\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payment machine, and specifically that it is one-way.
 *
 * This is what makes a delayed webhook harmless. Providers retry, and a retry
 * of `payment.failed` can land after the `payment.succeeded` that superseded
 * it; a machine that allowed the move would reverse a collected payment on
 * the strength of a stale message.
 */
#[CoversClass(PaymentStatus::class)]
final class PaymentStatusTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function legalMoves(): array
    {
        return [
            [PaymentStatus::PENDING, PaymentStatus::AUTHORIZED],
            [PaymentStatus::PENDING, PaymentStatus::SUCCEEDED],
            [PaymentStatus::PENDING, PaymentStatus::FAILED],
            [PaymentStatus::PENDING, PaymentStatus::CANCELLED],
            [PaymentStatus::AUTHORIZED, PaymentStatus::SUCCEEDED],
            [PaymentStatus::AUTHORIZED, PaymentStatus::FAILED],
            // Money arrived and can leave again, in ways that are themselves
            // recorded as refunds.
            [PaymentStatus::SUCCEEDED, PaymentStatus::REFUNDED],
            [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED],
            [PaymentStatus::SUCCEEDED, PaymentStatus::CHARGEBACK],
            [PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED],
            [PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::CHARGEBACK],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function illegalMoves(): array
    {
        return [
            // The delayed-webhook case, and the reason this machine exists.
            [PaymentStatus::SUCCEEDED, PaymentStatus::FAILED],
            [PaymentStatus::SUCCEEDED, PaymentStatus::PENDING],
            [PaymentStatus::SUCCEEDED, PaymentStatus::CANCELLED],
            // A failed payment is not retried into life: a retry is a new
            // attempt with its own provider reference, because the customer
            // may have used a different instrument.
            [PaymentStatus::FAILED, PaymentStatus::SUCCEEDED],
            [PaymentStatus::CANCELLED, PaymentStatus::SUCCEEDED],
            // Money already went back.
            [PaymentStatus::REFUNDED, PaymentStatus::SUCCEEDED],
            [PaymentStatus::CHARGEBACK, PaymentStatus::SUCCEEDED],
            [PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED],
        ];
    }

    #[DataProvider('legalMoves')]
    public function testLegalMovesArePermitted(string $from, string $to): void
    {
        self::assertTrue(PaymentStatus::permits($from, $to));
    }

    #[DataProvider('illegalMoves')]
    public function testIllegalMovesAreRefused(string $from, string $to): void
    {
        self::assertFalse(PaymentStatus::permits($from, $to));
    }

    public function testAnUnknownStatusGrantsNothing(): void
    {
        // Absence of a rule is a refusal. A status that reached the column
        // without a row in the table must not become a gateway to every
        // other state.
        self::assertFalse(PaymentStatus::permits('NONSENSE', PaymentStatus::SUCCEEDED));
    }

    public function testSettledMeansMoneyIsHeld(): void
    {
        self::assertTrue(PaymentStatus::isSettled(PaymentStatus::SUCCEEDED));
        // Some of it is still ours, and an invoice settled by a partly
        // refunded payment is still settled.
        self::assertTrue(PaymentStatus::isSettled(PaymentStatus::PARTIALLY_REFUNDED));

        foreach ([
            PaymentStatus::PENDING,
            PaymentStatus::AUTHORIZED,
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
            PaymentStatus::REFUNDED,
            PaymentStatus::CHARGEBACK,
        ] as $status) {
            self::assertFalse(PaymentStatus::isSettled($status), $status . ' should not read as settled');
        }
    }

    public function testAuthorizedIsNotSettled(): void
    {
        // The distinction that matters: an authorisation is a hold, not a
        // collection, and treating it as money in hand is how a platform
        // ships something it was never paid for.
        self::assertFalse(PaymentStatus::isSettled(PaymentStatus::AUTHORIZED));
    }

    public function testTerminalStatesAreFinal(): void
    {
        foreach ([
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
            PaymentStatus::REFUNDED,
            PaymentStatus::CHARGEBACK,
        ] as $status) {
            self::assertTrue(PaymentStatus::isFinal($status), $status . ' should be final');
        }

        self::assertFalse(PaymentStatus::isFinal(PaymentStatus::PENDING));
        self::assertFalse(PaymentStatus::isFinal(PaymentStatus::SUCCEEDED));
    }
}
