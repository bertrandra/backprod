<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Audit\Domain\AuditEntry;
use App\Finance\Domain\OfferRevenue;
use App\Finance\Domain\RenewalPeriod;
use App\Finance\Domain\RevenuePeriod;

/**
 * What an audit entry looks like on the wire.
 *
 * `actor` carries both the id and whether it was erased, so a reader can
 * tell an act nobody performed from one whose performer has since been
 * forgotten. Collapsing those into a bare null would quietly turn every
 * erasure into a system action.
 */
final class AdminPresenter
{
    /**
     * One month of turnover. Amounts stay in minor units with the currency
     * beside them: formatting is the reader's business and a float here
     * would lose cents the ledger has.
     *
     * @return array<string, mixed>
     */
    public static function turnover(RevenuePeriod $period): array
    {
        return [
            'month' => $period->periodStart,
            'currency' => $period->currency,
            'net_minor_units' => $period->netMinorUnits,
            'vat_minor_units' => $period->vatMinorUnits,
            'gross_minor_units' => $period->grossMinorUnits,
            // Beside the turnover, never subtracted from it.
            'credited_minor_units' => $period->creditedMinorUnits,
            'invoices_issued' => $period->invoicesIssued,
            'invoices_paid' => $period->invoicesPaid,
            // A settled month against one still moving. Without this a reader
            // cannot tell a final figure from this morning's.
            'closed' => $period->closed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function offerRevenue(OfferRevenue $offer): array
    {
        return [
            'offer_id' => $offer->offerId,
            'code' => $offer->offerCode,
            'name' => $offer->offerName,
            'currency' => $offer->currency,
            'net_minor_units' => $offer->netMinorUnits,
            'lines_billed' => $offer->linesBilled,
        ];
    }

    /**
     * Renewal as counts *and* a rate, with the rate null when nothing came up
     * for renewal.
     *
     * `measured` says which. Nothing renews yet — a finished period goes to
     * EXPIRED and no job calls renew() while the notice deadlines are
     * unconfirmed (R11) — so a bare 0 here would report an unbuilt feature as
     * total churn. Same distinction the usage endpoint draws between an
     * unmetered quota and one measured at zero.
     *
     * @return array<string, mixed>
     */
    public static function renewal(RenewalPeriod $period): array
    {
        return [
            'month' => $period->periodStart,
            'due' => $period->dueCount,
            'renewed' => $period->renewedCount,
            'ended' => $period->endedCount,
            'rate_percent' => $period->ratePercent(),
            'measured' => $period->dueCount > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditEntry(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurredAt->format(DATE_ATOM),
            'action' => $entry->action,
            'subject' => [
                'type' => $entry->subjectType,
                'id' => $entry->subjectId,
            ],
            'actor' => [
                'user_id' => $entry->userId,
                'forgotten' => $entry->actorWasForgotten(),
                'forgotten_at' => $entry->actorForgottenAt?->format(DATE_ATOM),
            ],
            // §30's correlation keys, named as the caller would search by.
            'tenant_id' => $entry->tenantId,
            'product_id' => $entry->productId,
            'project_id' => $entry->projectId,
            'request_id' => $entry->requestId,
            'detail' => $entry->detail,
        ];
    }
}
