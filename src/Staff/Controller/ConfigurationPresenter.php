<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Billing\Domain\SupplierDetails;
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
     * @param list<string> $missing
     *
     * @return array<string, mixed>
     */
    public static function configuration(
        Product $product,
        SupplierDetails $supplier,
        SupplierTaxSettings $tax,
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
            'can_invoice' => $missing === [],
            'missing' => $missing,
        ];
    }
}
