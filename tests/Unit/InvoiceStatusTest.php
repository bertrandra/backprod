<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\InvoiceStatus;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The invoice lifecycle, and specifically the moves it refuses.
 *
 * The legal moves are the boring half. What this exists to pin down is that
 * an issued invoice cannot be issued again — which would allocate a second
 * number for one document — and that a cancelled one cannot be paid, which
 * would put money against a debt that no longer exists.
 */
#[CoversClass(InvoiceStatus::class)]
final class InvoiceStatusTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function legalMoves(): array
    {
        return [
            [InvoiceStatus::DRAFT, InvoiceStatus::ISSUED],
            [InvoiceStatus::DRAFT, InvoiceStatus::CANCELLED],
            [InvoiceStatus::ISSUED, InvoiceStatus::PAID],
            [InvoiceStatus::ISSUED, InvoiceStatus::CANCELLED],
            [InvoiceStatus::ISSUED, InvoiceStatus::CREDITED],
            // Money moved and then had to be given back: a paid invoice is
            // corrected by a credit note, never by cancelling it.
            [InvoiceStatus::PAID, InvoiceStatus::CREDITED],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function illegalMoves(): array
    {
        return [
            // A second legal number for one document.
            [InvoiceStatus::ISSUED, InvoiceStatus::ISSUED],
            // Money against a debt that no longer exists.
            [InvoiceStatus::CANCELLED, InvoiceStatus::PAID],
            // Undoing a cancellation by re-issuing, which would reuse a
            // number that has already been reported as void.
            [InvoiceStatus::CANCELLED, InvoiceStatus::ISSUED],
            // Cancelling something already settled. The correction is a
            // credit note; cancelling would erase a payment that happened.
            [InvoiceStatus::PAID, InvoiceStatus::CANCELLED],
            // Credited is the end of the line.
            [InvoiceStatus::CREDITED, InvoiceStatus::PAID],
            // Skipping straight past issue: a draft has no number, and
            // paying it would settle a document that was never sent.
            [InvoiceStatus::DRAFT, InvoiceStatus::PAID],
            // A status this milestone stores but cannot yet reach. It is
            // refused rather than silently permitted, so wiring the
            // e-invoicing adapter has to extend the table deliberately.
            [InvoiceStatus::ISSUED, InvoiceStatus::SUBMITTED],
        ];
    }

    #[DataProvider('legalMoves')]
    public function testLegalMovesArePermitted(string $from, string $to): void
    {
        self::assertTrue(InvoiceStatus::permits($from, $to));

        InvoiceStatus::assertPermits($from, $to);
    }

    #[DataProvider('illegalMoves')]
    public function testIllegalMovesAreRefused(string $from, string $to): void
    {
        self::assertFalse(InvoiceStatus::permits($from, $to));

        $this->expectException(ConflictException::class);
        InvoiceStatus::assertPermits($from, $to);
    }

    public function testAnUnknownStateGrantsNothing(): void
    {
        // Absence of a rule is a refusal, not a permission. A status that
        // reached the column without a row in the table must not become a
        // gateway to every other state.
        self::assertFalse(InvoiceStatus::permits('NONSENSE', InvoiceStatus::PAID));
    }

    public function testOnlyADraftIsNotFinal(): void
    {
        self::assertFalse(InvoiceStatus::isFinal(InvoiceStatus::DRAFT));

        foreach ([
            InvoiceStatus::ISSUED,
            InvoiceStatus::READY_FOR_EINVOICE,
            InvoiceStatus::SUBMITTED,
            InvoiceStatus::ACCEPTED,
            InvoiceStatus::REJECTED,
            InvoiceStatus::PAID,
            InvoiceStatus::CANCELLED,
            InvoiceStatus::CREDITED,
        ] as $status) {
            self::assertTrue(InvoiceStatus::isFinal($status), $status . ' should be final');
        }
    }
}
