<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Billing\Domain\SupplierDetails;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Domain\ProductSettings;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Tax\Domain\SupplierTaxSettings;

/**
 * What a product needs configured before it can take money.
 *
 * ADR-042 gave the console a way to create a product and ADR-043 a way to price
 * it, and a checkout against a product created that way still refused:
 * `BILLING_NOT_CONFIGURED`, because an invoice must name its issuer and the
 * issuer lives in `product_configuration`. Exactly one thing on the platform
 * wrote that table — `bin/seed-demo.php` — so the only products that could
 * invoice were the demo's and the installer's.
 *
 * Two keys, and deliberately only two:
 *
 *   - `billing_supplier`, the legal identity an invoice must carry (§25);
 *   - `tax`, the supplier's own fiscal position, which §25.3 requires to be
 *     configured rather than derived — whether the supplier is registered for
 *     the One Stop Shop is a dated fact about the business, not something to
 *     infer from turnover.
 *
 * **Not a free-form JSONB editor.** Every other key in that table is read by
 * code that names it, so a writer taking an arbitrary key would let a typo store
 * configuration nothing reads — which on screen is indistinguishable from
 * configuration that did not save.
 *
 * **Reads are not recorded; writes are.** Non-negotiable #21 traces staff
 * crossing into a *tenant's* data, and a product's own fiscal identity is the
 * platform's. What is recorded is every change to it, because an invoice carries
 * a snapshot of this taken at the moment it was raised, and "why does the
 * January batch name a different issuer?" is answerable only from a trail.
 */
final class ConfigurationDesk
{
    public function __construct(
        private readonly ProductSettings $settings,
        private readonly ProductRepository $products,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * Everything configured for a product, and whether it can invoice.
     *
     * The readiness is computed here rather than left to the screen: "can this
     * product raise an invoice" is the same question {@see
     * \App\Billing\Service\SupplierIdentity} answers when a checkout runs, and
     * two answers to it would eventually disagree — with the console saying
     * ready and the checkout refusing.
     *
     * @return array{
     *     product: Product,
     *     supplier: SupplierDetails,
     *     tax: SupplierTaxSettings,
     *     missing: list<string>,
     * }
     */
    public function configuration(string $productCode): array
    {
        $product = $this->product($productCode);
        $configured = $this->settings->all($product->id);

        $supplier = SupplierDetails::parse($configured[SupplierDetails::CONFIGURATION_KEY] ?? null);

        return [
            'product' => $product,
            'supplier' => $supplier,
            // The supplier's country is the fallback, exactly as the invoice
            // path resolves it: the tax key may be absent entirely and the
            // regime still has to be knowable.
            'tax' => SupplierTaxSettings::fromConfiguration(
                $configured,
                $supplier->countryCode() ?? '',
            ),
            'missing' => $supplier->missing(),
        ];
    }

    /**
     * Sets the identity invoices will name.
     *
     * **An incomplete identity is refused rather than stored.** The console
     * exists to make invoicing possible, and saving something that cannot
     * invoice while answering 200 is how a broken form looks like a working one
     * — the failure would surface later, to a customer, at the checkout.
     */
    public function setSupplier(
        StaffIdentity $staff,
        string $productCode,
        SupplierDetails $details,
    ): SupplierDetails {
        $product = $this->product($productCode);
        $missing = $details->missing();

        if ($missing !== []) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'An invoice must name who is issuing it and from which country.',
                ['missing' => $missing],
            );
        }

        $this->settings->put(
            $product->id,
            SupplierDetails::CONFIGURATION_KEY,
            $details->snapshot(),
        );

        // The legal name and the country, not the whole document: those two are
        // what changed the meaning of every invoice raised afterwards, and an
        // address correction reads as noise beside them.
        $this->record($staff, $product, 'CONFIGURE_BILLING', [
            'legal_name' => $details->snapshot()['legal_name'],
            'country_code' => $details->countryCode(),
        ]);

        return $details;
    }

    /**
     * Sets the supplier's own fiscal position (§25.3).
     */
    public function setTax(
        StaffIdentity $staff,
        string $productCode,
        SupplierTaxSettings $tax,
    ): SupplierTaxSettings {
        $product = $this->product($productCode);

        $this->settings->put(
            $product->id,
            SupplierTaxSettings::CONFIGURATION_KEY,
            $tax->toConfiguration(),
        );

        // Recorded in full: all four decide how a cross-border sale is taxed,
        // and getting one wrong is a VAT return filed in the wrong country.
        $this->record($staff, $product, 'CONFIGURE_TAX', $tax->toConfiguration());

        return $tax;
    }

    private function product(string $code): Product
    {
        $product = $this->products->findByCode($code);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', ['product' => $code], 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(StaffIdentity $staff, Product $product, string $action, array $detail): void
    {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // No tenant: this is the product's own fiscal identity, and naming
            // a customer here would invent one the decision was not about.
            null,
            $product->id,
            $action,
            'product_configuration',
            $product->id,
            StaffPermission::PRODUCTS_MANAGE,
            $detail,
        ));
    }
}
