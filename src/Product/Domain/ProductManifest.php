<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What a product deployed beside the platform says about itself.
 *
 * A product is the authority on its own document format — it writes the
 * migrations, it ships the spec — and until now it had no way to say so. The
 * accepted versions lived only in `product_configuration`, written by one staff
 * screen, and a release of the product that saved in a newer version was
 * refused `UNSUPPORTED_SCHEMA_VERSION` until somebody remembered to open that
 * screen. It has cost two outages, and each looked like a bug in the product.
 *
 * So the product serves a manifest at a fixed path under its own `app_url`:
 *
 *     GET https://plan.example/.well-known/product.json
 *
 *     {"product": "plan", "app_version": "2.3.0", "schema_versions": [1, 2, 3]}
 *
 * **It is read and never obeyed.** What arrives here is a claim by a host the
 * platform's staff named, not an instruction: the console shows it beside what
 * the database holds and an operator applies the difference. A manifest that
 * wrote straight into `product_configuration` would make the product's own
 * host able to re-open a version the platform had deliberately retired
 * (ADR-018), and would hand a compromised or mistyped `app_url` the ability to
 * reconfigure a product nobody was looking at.
 *
 * **It names its product**, and the platform checks that against the product it
 * asked about. An `app_url` pointing at the wrong host — a copy-paste between
 * two products, a staging address left in place — otherwise maps one product's
 * versions onto another's configuration, silently and plausibly.
 *
 * Unknown keys are ignored rather than refused: the manifest is the product's
 * file and it will grow things the platform has no opinion about.
 */
final class ProductManifest
{
    /** Where a product serves it, under its own `app_url`. */
    public const PATH = '/.well-known/product.json';

    /**
     * The most a manifest may declare, matching {@see \App\Project\Domain\SchemaVersions::LIMIT}
     * so that what a product can say and what the platform can store are the
     * same bound. A longer list is a mistake or a file that is not a manifest.
     */
    public const LIMIT = 64;

    /**
     * @param list<int> $schemaVersions
     */
    private function __construct(
        public readonly string $product,
        public readonly ?string $appVersion,
        public readonly array $schemaVersions,
    ) {
    }

    /**
     * What the product served, or null when it is not a manifest.
     *
     * Null rather than an exception: an unreachable host, a 404 and a login
     * page that answers 200 with HTML are all the same thing to the caller —
     * the product did not say — and only one of them is exceptional.
     *
     * `product` and `schema_versions` are required because a file lacking
     * either cannot be acted on. `app_version` is not: it is shown to an
     * operator and decides nothing.
     */
    public static function parse(mixed $body): ?self
    {
        if (!is_array($body)) {
            return null;
        }

        $product = $body['product'] ?? null;
        $versions = $body['schema_versions'] ?? null;

        if (!is_string($product) || trim($product) === '' || !is_array($versions)) {
            return null;
        }

        $clean = self::clean($versions);

        // An empty list is refused rather than read as "accepts nothing". A
        // product saying that about itself is indistinguishable here from a
        // file that lost its array, and acting on it would offer an operator a
        // button that takes the product offline.
        if ($clean === [] || count($clean) > self::LIMIT) {
            return null;
        }

        $appVersion = $body['app_version'] ?? null;

        return new self(
            trim($product),
            is_string($appVersion) && trim($appVersion) !== '' ? trim($appVersion) : null,
            $clean,
        );
    }

    /**
     * Whether this manifest is the one the platform asked for.
     *
     * Compared case-insensitively on a trimmed value, because a product code is
     * an identifier an operator typed into a file by hand.
     */
    public function describes(string $code): bool
    {
        return strcasecmp($this->product, $code) === 0;
    }

    /**
     * Deduplicated and ordered, the same way {@see \App\Project\Domain\SchemaVersions}
     * cleans a stored row — so a manifest and the configuration it is compared
     * against mean the same thing by "a version".
     *
     * A float is dropped rather than cast: `3.0` from a JSON file is a number
     * the writer did not mean as a version, and casting it would invent one.
     *
     * @param array<array-key, mixed> $versions
     *
     * @return list<int>
     */
    private static function clean(array $versions): array
    {
        $clean = [];

        foreach ($versions as $version) {
            if (is_int($version) && $version > 0 && !in_array($version, $clean, true)) {
                $clean[] = $version;
            }
        }

        sort($clean);

        return $clean;
    }
}
