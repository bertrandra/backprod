<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * A billable capability: max_projects, advanced_3d, api_access (§13).
 *
 * Distinct from a ProductFeature, which says what a product has built. This
 * says what a customer can buy. A product may ship something it does not
 * sell, and may sell something it has not shipped yet; one table cannot say
 * both.
 */
final class Feature
{
    public const BOOLEAN = 'BOOLEAN';
    public const QUOTA = 'QUOTA';

    public function __construct(
        public readonly string $id,
        public readonly string $code,
        /** The English, which is the key and the fallback (ADR-050). */
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $unit,
        /** What a customer reads beside the name; English, optional. */
        public readonly ?string $description = null,
        /**
         * What the operator wrote in the other four languages, by locale
         * (2026-09-24). Empty is the ordinary case and is not a gap: it
         * means nobody has translated this yet, and English answers.
         *
         * @var array<string, array{name: ?string, description: ?string}>
         */
        public readonly array $translations = [],
    ) {
    }

    /**
     * Whether this is something you have a number of, rather than something
     * you either have or do not.
     */
    public function isQuota(): bool
    {
        return $this->kind === self::QUOTA;
    }

    /**
     * The name in one language, falling back to the English.
     *
     * Here rather than in a presenter because the fallback is a rule about
     * what a feature *is* — English is its key — and a second copy of it in
     * some controller would be the place the two answers start to differ.
     */
    public function nameIn(string $locale): string
    {
        $translated = $this->translations[$locale]['name'] ?? null;

        return $translated === null || $translated === '' ? $this->name : $translated;
    }

    /** The description in one language, or the English, or nothing at all. */
    public function descriptionIn(string $locale): ?string
    {
        $translated = $this->translations[$locale]['description'] ?? null;

        return $translated === null || $translated === '' ? $this->description : $translated;
    }
}
