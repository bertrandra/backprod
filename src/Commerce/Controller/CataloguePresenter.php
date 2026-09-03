<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\Feature;
use App\Commerce\Domain\Offer;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for the catalogue, wherever it is returned.
 *
 * Money is returned as integer minor units with its currency, never as a
 * formatted string or a float. A client that wants "€19.00" can format it;
 * a client that receives 19.0 has already lost the ability to.
 */
final class CataloguePresenter
{
    /**
     * @return array{id: string, code: string, name: string, rank: int}
     */
    public static function plan(Plan $plan): array
    {
        return ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'rank' => $plan->rank];
    }

    /**
     * @param list<Plan> $plans
     *
     * @return list<array<string, mixed>>
     */
    public static function plans(array $plans): array
    {
        return array_map(self::plan(...), $plans);
    }

    /**
     * @return array{id: string, code: string, name: string, kind: string, unit: string|null}
     */
    public static function feature(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'code' => $feature->code,
            'name' => $feature->name,
            'kind' => $feature->kind,
            'unit' => $feature->unit,
        ];
    }

    /**
     * @param list<Feature> $features
     *
     * @return list<array<string, mixed>>
     */
    public static function features(array $features): array
    {
        return array_map(self::feature(...), $features);
    }

    /**
     * @return array<string, mixed>
     */
    public static function offer(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'code' => $offer->code,
            'name' => $offer->name,
            'plan' => self::plan($offer->plan),
            // Never null in practice — an offer is only presented once a
            // sellable version has been chosen — but typed honestly rather
            // than asserted away.
            'version' => $offer->currentVersion === null ? null : self::version($offer->currentVersion),
        ];
    }

    /**
     * @param list<Offer> $offers
     *
     * @return list<array<string, mixed>>
     */
    public static function offers(array $offers): array
    {
        return array_map(self::offer(...), $offers);
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(OfferVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'billing_period' => $version->billingPeriod,
            'price' => [
                'amount_minor_units' => $version->priceMinorUnits,
                'currency' => $version->currency,
            ],
            // The window in which this version may be sold — not a
            // subscription period, which §12 keeps deliberately separate.
            'valid_from' => self::moment($version->validFrom),
            'valid_until' => $version->validUntil === null ? null : self::moment($version->validUntil),
            'grants' => array_map(self::grant(...), $version->grants),
        ];
    }

    /**
     * @return array{feature: string, name: string, kind: string, unit: string|null, limit: int|null, unlimited: bool}
     */
    public static function grant(OfferGrant $grant): array
    {
        return [
            'feature' => $grant->feature->code,
            'name' => $grant->feature->name,
            'kind' => $grant->feature->kind,
            'unit' => $grant->feature->unit,
            // limit is null for a boolean capability and for an unlimited
            // quota, which are not the same thing — so the flag says which.
            'limit' => $grant->limit,
            'unlimited' => $grant->isUnlimited(),
        ];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
