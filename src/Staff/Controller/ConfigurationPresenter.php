<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Billing\Domain\SupplierDetails;
use App\Product\Domain\ManifestAnswer;
use App\Product\Domain\Product;
use App\Tax\Domain\SupplierTaxSettings;

/**
 * A product's fiscal configuration, as the console reads it.
 *
 * `can_invoice` and `missing` are the same fact twice on purpose: a flag for a
 * screen deciding whether to show a warning, and the field names for the screen
 * saying *what* to fix. A console holding only the flag would have to guess.
 */
final class ConfigurationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function supplier(SupplierDetails $details): array
    {
        // The snapshot as it is: every key present, nulls included, so a form
        // binding to it finds the same shape whether the product was configured
        // years ago or never.
        return $details->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public static function tax(SupplierTaxSettings $tax): array
    {
        return $tax->toConfiguration();
    }

    /**
     * @param list<int>    $schemaVersions
     * @param list<string> $missing
     *
     * @return array<string, mixed>
     */
    public static function configuration(
        Product $product,
        SupplierDetails $supplier,
        SupplierTaxSettings $tax,
        array $schemaVersions,
        array $missing,
    ): array {
        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
            ],
            'billing_supplier' => self::supplier($supplier),
            'tax' => self::tax($tax),
            // Empty means the product accepts no project at all, which is the
            // state of one nobody has configured — said as an empty list
            // rather than omitted, so a screen can tell it from a field it
            // failed to read.
            'project_schema_versions' => $schemaVersions,
            'can_invoice' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * What the product said about itself, and what applying it would change.
     *
     * `declared` is null whenever the product did not answer with a manifest,
     * and `error` says which of the ordinary silences it was. Both are present
     * in every answer rather than one replacing the other, so a screen binds to
     * one shape — the same reasoning as `can_invoice` beside `missing`.
     *
     * `adds` never proposes a removal: retiring a version refuses edits on
     * documents customers already hold (ADR-018), and that stays something a
     * person decides rather than something a remote file suggests.
     *
     * @param list<int> $stored
     * @param list<int> $adds
     *
     * @return array<string, mixed>
     */
    public static function manifest(
        Product $product,
        ManifestAnswer $answer,
        array $stored,
        array $adds,
    ): array {
        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                // The address this was asked at, so an operator reading
                // `WRONG_PRODUCT` can see at a glance which host answered.
                'app_url' => $product->appUrl,
            ],
            'declared' => $answer->manifest === null ? null : [
                'app_version' => $answer->manifest->appVersion,
                'schema_versions' => $answer->manifest->schemaVersions,
            ],
            'error' => $answer->error,
            'project_schema_versions' => $stored,
            'adds' => $adds,
        ];
    }
}
