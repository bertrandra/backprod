<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * Where a product keeps its demonstrations, and how one is embedded
 * (2026-11-22).
 *
 * **The page editor asked for an address.** A demonstration band and each
 * side of a comparison take the address of a live view to embed, and the
 * operator typed it: a long query string, with a number in the middle that
 * says which demonstration is meant and that nobody remembers. The product
 * knows which ones it has; the console did not ask it.
 *
 * So a product may be told two things, both **relative to its own
 * `app_url`** and both carrying `{n}`, the demonstration's number:
 *
 * ```text
 * source   admin/vitrine/{n}                      answers 200 where number n exists
 * embed    ?mode=demo&file={n}&x={width}&y={height}   what the page embeds for it
 * ```
 *
 * **Configuration, never a constant.** Those two strings are one product's
 * own shape. Written into the platform they would be that product's URL
 * scheme compiled into a backend every product shares — the thing
 * `gate:products` forbids by name, arriving by address instead.
 *
 * **Relative, so the platform cannot be pointed elsewhere.** The read this
 * drives leaves the host. Anchored to `app_url` — refused with a scheme, a
 * host, a parent segment or a backslash — it can only ever ask the product
 * the platform already embeds and already reads a manifest from.
 */
final class DemonstrationSource
{
    public const CONFIGURATION_KEY = 'demonstrations';

    /** What stands for the demonstration's number in both strings. */
    public const NUMBER = '{n}';

    /**
     * How many numbers are asked, from 1.
     *
     * A bound and not a setting: each number is a request to somebody else's
     * server on every opening of the list, and a product with more than this
     * many demonstrations wants to publish a list rather than be probed.
     */
    public const UP_TO = 20;

    private const LONGEST = 500;

    private function __construct(
        public readonly string $source,
        public readonly string $embed,
    ) {
    }

    /** What an operator chose, or null where either string cannot be used. */
    public static function chosen(string $source, string $embed): ?self
    {
        $source = trim($source);
        $embed = trim($embed);

        return self::usable($source) && self::usable($embed) ? new self($source, $embed) : null;
    }

    /** What the configuration holds, or null where it holds nothing usable. */
    public static function read(mixed $stored): ?self
    {
        if (!is_array($stored)) {
            return null;
        }

        $source = $stored['source'] ?? null;
        $embed = $stored['embed'] ?? null;

        return is_string($source) && is_string($embed) ? self::chosen($source, $embed) : null;
    }

    /**
     * @return array{source: string, embed: string}
     */
    public function toArray(): array
    {
        return ['source' => $this->source, 'embed' => $this->embed];
    }

    /** Where demonstration `$n` is asked for, under the product's address. */
    public function sourceUrl(string $appUrl, int $n): string
    {
        return self::under($appUrl, $this->source, $n);
    }

    /** What a page embeds to show demonstration `$n`. */
    public function embedUrl(string $appUrl, int $n): string
    {
        return self::under($appUrl, $this->embed, $n);
    }

    private static function under(string $appUrl, string $relative, int $n): string
    {
        return rtrim($appUrl, '/') . '/' . str_replace(self::NUMBER, (string) $n, $relative);
    }

    private static function usable(string $relative): bool
    {
        if ($relative === '' || mb_strlen($relative) > self::LONGEST || !str_contains($relative, self::NUMBER)) {
            return false;
        }

        // Nothing that could leave the product's address: a scheme, a
        // host (`//elsewhere`), a leading slash that a careless join would
        // double, a parent segment, a backslash some servers read as a
        // slash, or a character that is not printable ASCII.
        if (preg_match('/^[\x21-\x7E]+$/', $relative) !== 1) {
            return false;
        }

        return !str_starts_with($relative, '/')
            && !str_contains($relative, '\\')
            && !str_contains($relative, '..')
            && !str_contains($relative, '//')
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $relative) !== 1
            && !str_contains($relative, '@')
            && !str_contains($relative, '#');
    }
}
