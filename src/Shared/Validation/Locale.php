<?php

declare(strict_types=1);

namespace App\Shared\Validation;

/**
 * The languages the platform speaks (2026-09-19, ADR-050).
 *
 * English is the language of the code, the contract and every default;
 * the others are catalogues on top. One list, so a migration's CHECK, a
 * profile's validation and a mail's fallback cannot disagree.
 */
final class Locale
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const ALL = ['en', 'fr', 'es', 'de', 'it'];

    /**
     * The four a value can be *translated into* (2026-09-24).
     *
     * English is not one of them: it lives on the row itself, as the key
     * and the fallback, and a second place to write it would be a second
     * answer to "what is this called" the first time somebody edited one.
     *
     * @var list<string>
     */
    public const TRANSLATABLE = ['fr', 'es', 'de', 'it'];

    public static function isKnown(string $locale): bool
    {
        return in_array($locale, self::ALL, true);
    }

    public static function isTranslatable(string $locale): bool
    {
        return in_array($locale, self::TRANSLATABLE, true);
    }

    /** A known locale, or the default. */
    public static function of(?string $locale): string
    {
        return $locale !== null && self::isKnown($locale) ? $locale : self::DEFAULT;
    }
}
