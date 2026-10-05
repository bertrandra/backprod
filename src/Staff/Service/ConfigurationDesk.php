<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Billing\Domain\SupplierDetails;
use App\Commerce\Domain\RenewalPolicy;
use App\Product\Domain\ManifestAnswer;
use App\Product\Domain\Product;
use App\Product\Domain\ProductManifests;
use App\Product\Domain\ProductRepository;
use App\Product\Domain\ProductSettings;
use App\Project\Domain\SchemaDrift;
use App\Project\Domain\SchemaVersions;
use App\Project\Service\SchemaVersionPolicy;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;
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
 * Three keys, and deliberately only three:
 *
 *   - `billing_supplier`, the legal identity an invoice must carry (§25);
 *   - `tax`, the supplier's own fiscal position, which §25.3 requires to be
 *     configured rather than derived — whether the supplier is registered for
 *     the One Stop Shop is a dated fact about the business, not something to
 *     infer from turnover.
 *   - `project_schema_versions`, which document versions the product accepts
 *     (non-negotiable #10).
 *
 * **The third closed the same hole as the first two, one layer down**
 * (2026-09-29). `PostgresProductDirectory::create()` writes a row in `products`
 * and none in `product_configuration`, and {@see SchemaVersionPolicy} is
 * explicit that a product which has declared nothing accepts nothing — so a
 * product created through the console could never accept a single project, of
 * any version, and nothing on the platform could change that. Only
 * `bin/seed-demo.php` had ever written the key, which is exactly what ADR-042
 * found about the billing identity.
 *
 * **Not a free-form JSONB editor.** Every other key in that table is read by
 * code that names it, so a writer taking an arbitrary key would let a typo store
 * configuration nothing reads — which on screen is indistinguishable from
 * configuration that did not save. A third named key is the shape that rule
 * prescribes; an arbitrary-key writer is what it forbids.
 */
final class ConfigurationDesk
{
    public function __construct(
        private readonly ProductSettings $settings,
        private readonly ProductRepository $products,
        private readonly ProductManifests $manifests,
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
     *     schemaVersions: list<int>,
     *     renewal: RenewalPolicy,
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
            // Read through the same tolerant path the project write uses, so
            // the screen shows what the policy would actually enforce rather
            // than what the row happens to contain.
            'schemaVersions' => SchemaVersions::read(
                $configured[SchemaVersionPolicy::CONFIGURATION_KEY] ?? null,
            ),
            // The supplier's country is the fallback, exactly as the invoice
            // path resolves it: the tax key may be absent entirely and the
            // regime still has to be knowable.
            'tax' => SupplierTaxSettings::fromConfiguration(
                $configured,
                $supplier->countryCode() ?? '',
            ),
            // Read through the policy the renewal pass reads, so the screen
            // shows what the pass would do rather than what the row holds.
            'renewal' => RenewalPolicy::fromConfiguration($configured),
            'missing' => $supplier->missing(),
        ];
    }

    /**
     * Whether this product's subscriptions renew by themselves, and how many
     * days before the period ends the customer is asked to pay (2026-10-05).
     *
     * A fourth named key, for the reason the first three are named: the renewal
     * pass reads `renewal` and nothing else, so it is written by a writer that
     * knows that word.
     */
    public function setRenewal(
        StaffIdentity $staff,
        string $productCode,
        RenewalPolicy $policy,
    ): RenewalPolicy {
        $product = $this->product($productCode);

        $this->settings->put(
            $product->id,
            RenewalPolicy::CONFIGURATION_KEY,
            $policy->toArray(),
        );

        return $policy;
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

        return $tax;
    }

    /**
     * What the product itself says it accepts, asked of the product.
     *
     * A product is the authority on its own document format — it writes the
     * migrations and ships the spec — and the platform's list is a copy of that
     * fact, made by hand, once, by whoever remembered. The copy has fallen
     * behind twice, and both times every save was refused and it looked like a
     * bug in the product.
     *
     * **Read, shown, and never applied here.** This answers a GET and writes
     * nothing: the operator sees what the product declares beside what the
     * database holds, and applies the difference with
     * {@see self::setSchemaVersions()} if they want it. A fetch that wrote
     * would hand the product's own host the ability to re-open a version the
     * platform had deliberately retired (ADR-018) and to reconfigure a product
     * nobody was looking at — and `app_url` is one staff field away from
     * pointing somewhere else.
     *
     * **Adding only.** `missing` is what applying would add; it never proposes
     * a removal, because removing a version refuses edits on documents
     * customers already hold, and that is a decision somebody makes rather than
     * one a remote file proposes.
     *
     * @return array{product: Product, answer: ManifestAnswer, stored: list<int>, missing: list<int>}
     */
    public function manifest(string $productCode): array
    {
        $product = $this->product($productCode);

        $stored = SchemaVersions::read(
            $this->settings->all($product->id)[SchemaVersionPolicy::CONFIGURATION_KEY] ?? null,
        );

        $answer = $product->appUrl === null || trim($product->appUrl) === ''
            // Nothing is fetched for a product that runs inside this shell.
            // There is no host to ask, which is a fact about the deployment
            // rather than a failure of one.
            ? ManifestAnswer::failed(ManifestAnswer::NO_ADDRESS)
            : $this->manifests->of($product->appUrl, $product->code);

        return [
            'product' => $product,
            'answer' => $answer,
            'stored' => $stored,
            // The same subtraction the preflight composes, from the same place:
            // a screen offering to add something `bin/preflight.php` did not
            // consider missing would be two answers to one question.
            'missing' => $answer->manifest === null
                ? []
                : SchemaDrift::missing($answer->manifest->schemaVersions, $stored),
        ];
    }

    /**
     * Sets which project document schema versions the product accepts:
     * every element decides whether a document a customer is about to save is
     * accepted or refused.
     *
     * @return list<int>
     */
    public function setSchemaVersions(
        StaffIdentity $staff,
        string $productCode,
        SchemaVersions $versions,
    ): array {
        $product = $this->product($productCode);

        $this->settings->put(
            $product->id,
            SchemaVersionPolicy::CONFIGURATION_KEY,
            $versions->toConfiguration(),
        );

        return $versions->versions;
    }

    private function product(string $code): Product
    {
        $product = $this->products->findByCode($code);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', ['product' => $code], 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }

}
