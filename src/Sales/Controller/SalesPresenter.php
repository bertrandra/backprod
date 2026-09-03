<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Billing\Controller\InvoicePresenter;
use App\Billing\Domain\Money;
use App\Sales\Domain\Order;
use App\Sales\Domain\Quote;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for quotes and orders.
 *
 * `open` on a quote is computed against the clock rather than reported from
 * the status column, because that is the question a client actually has —
 * "can I still accept this?" — and the column can be stale by a year.
 */
final class SalesPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function quote(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'status' => $quote->status,
            'open' => $quote->isOpenAt(new DateTimeImmutable()),
            'offer_version_id' => $quote->offerVersionId,
            'net' => self::money($quote->net),
            'vat' => self::money($quote->vat),
            'gross' => self::money($quote->gross),
            'valid_until' => self::moment($quote->validUntil),
            'customer' => $quote->customer,
            'sent_at' => self::nullableMoment($quote->sentAt),
            'decided_at' => self::nullableMoment($quote->decidedAt),
            'created_at' => self::moment($quote->createdAt),
            'lines' => InvoicePresenter::lines($quote->lines),
        ];
    }

    /**
     * @param list<Quote> $quotes
     *
     * @return list<array<string, mixed>>
     */
    public static function quotes(array $quotes): array
    {
        return array_map(self::quote(...), $quotes);
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'quote_id' => $order->quoteId,
            'offer_version_id' => $order->offerVersionId,
            // The gate, readable straight off the document: an invoice from
            // fulfilment onwards, a subscription only once that invoice is
            // paid. Both non-null is non-negotiable #20's chain complete.
            'subscription_id' => $order->subscriptionId,
            'invoice_id' => $order->invoiceId,
            'net' => self::money($order->net),
            'vat' => self::money($order->vat),
            'gross' => self::money($order->gross),
            'completed_at' => self::nullableMoment($order->completedAt),
            'created_at' => self::moment($order->createdAt),
            'lines' => InvoicePresenter::lines($order->lines),
        ];
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<array<string, mixed>>
     */
    public static function orders(array $orders): array
    {
        return array_map(self::order(...), $orders);
    }

    /**
     * @return array{minor_units: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['minor_units' => $money->minorUnits, 'currency' => $money->currency];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
