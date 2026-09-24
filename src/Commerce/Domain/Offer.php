<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * What is sold (§12).
 *
 * The offer is the stable identity; its terms live in versions. Nothing here
 * changes when a price does, which is what lets a subscription name an offer
 * without ambiguity about which terms it meant.
 */
final class Offer
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly Plan $plan,
        public readonly ?OfferVersion $currentVersion,
        /**
         * Whether the platform advertises this offer to people with no
         * account. Distinct from being on sale: a price negotiated with one
         * reseller is sellable and is nobody else's business.
         */
        public readonly bool $publiclyListed = false,
        /**
         * What the operator calls it in the four other languages
         * (2026-09-24). The English on this object is the key and the
         * fallback; a version's price and terms are frozen, a translation
         * of its name is not (ADR-033, `docs/translatable-fields-spec.md`).
         *
         * @var array<string, string>
         */
        public readonly array $translations = [],
    ) {
    }

    /** The name in one language, falling back to the English. */
    public function nameIn(string $locale): string
    {
        $translated = $this->translations[$locale] ?? null;

        return $translated === null || $translated === '' ? $this->name : $translated;
    }
}
