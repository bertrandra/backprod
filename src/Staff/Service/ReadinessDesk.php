<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Billing\Domain\SupplierDetails;
use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferVersion;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Domain\ProductSettings;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\SetupStep;
use App\Tax\Domain\SupplierTaxSettings;
use DateTimeImmutable;

/**
 * What a product still needs before a stranger can buy from it.
 *
 * This exists because the chain was discoverable only by walking into its
 * refusals. A fresh installation has a product and nothing else; creating an
 * offer fails because there is no plan; publishing succeeds and the storefront
 * still shows nothing because advertising is a separate decision; and a
 * checkout that gets all the way through refuses with
 * `BILLING_NOT_CONFIGURED` because an invoice must name its issuer. Every one
 * of those refusals is correct, and together they are a maze.
 *
 * **The order is the dependency order, and it is the point.** A step that
 * cannot be started until an earlier one is done sits after it, so following
 * the list top to bottom never meets a refusal. That is also why this is not a
 * checklist of independent boxes: the shape carries information.
 *
 * **Facts, not sentences.** Each step carries a key and what was counted or
 * found missing; the words belong to whatever renders it. And every fact is
 * read through the same port the enforcing code reads — `SupplierDetails` for
 * the issuer, `CatalogueRepository` for the catalogue, the clock for
 * sellability — so this cannot report ready where a sale would refuse.
 */
final class ReadinessDesk
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSettings $settings,
        private readonly CatalogueRepository $catalogue,
    ) {
    }

    /**
     * The chain, in the order it has to be completed.
     *
     * @param array{name: string, sandbox: bool}|null $paymentProvider the
     *                                 payment provider this *deployment* has,
     *                                 or null for none. Passed in rather than
     *                                 read here: it is a fact about the server's
     *                                 configuration, not about the product, and
     *                                 the only thing that knows it is an
     *                                 application service this one may not
     *                                 depend on. The name and whether it is a
     *                                 sandbox travel with the step so the
     *                                 console can say "Stripe (sandbox)"
     *                                 rather than "configured" (ADR-048).
     *
     * @return array{product: Product, steps: list<SetupStep>}
     */
    public function of(string $productCode, ?array $paymentProvider): array
    {
        $product = $this->products->findByCode($productCode);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', ['product' => $productCode], 'PRODUCT_NOT_FOUND');
        }

        $configured = $this->settings->all($product->id);
        $supplier = SupplierDetails::parse($configured[SupplierDetails::CONFIGURATION_KEY] ?? null);

        $plans = $this->catalogue->plansFor($product->id);
        $features = $this->catalogue->features();
        $offers = $this->catalogue->offersFor($product->id);
        $advertised = $this->catalogue->publiclyListedOffersFor($product->id);

        $now = new DateTimeImmutable();

        return [
            'product' => $product,
            'steps' => [
                // Active, not merely present. A retired product closes every
                // door into itself, and a chain that called that "done" would
                // be describing a product nobody can reach.
                new SetupStep('product', $product->active, true, [
                    'code' => $product->code,
                    'active' => $product->active,
                ]),

                new SetupStep('billing_identity', $supplier->isComplete(), true, [
                    'missing' => $supplier->missing(),
                ]),

                // Not blocking: the reader of this key falls back to the
                // supplier's own country, so an unwritten tax position still
                // invoices correctly for a supplier selling at home. It is
                // listed because selling across a border without stating the
                // position is how VAT gets filed in the wrong country.
                new SetupStep(
                    'tax',
                    array_key_exists(SupplierTaxSettings::CONFIGURATION_KEY, $configured),
                    false,
                    ['country' => $supplier->countryCode()],
                ),

                new SetupStep('plans', $plans !== [], true, ['count' => count($plans)]),

                // Not blocking either: an offer that grants access to the
                // product and nothing more is a legitimate offer.
                new SetupStep('features', $features !== [], false, ['count' => count($features)]),

                new SetupStep('offers', $offers !== [], true, ['count' => count($offers)]),

                // Asked of the clock, not of a status column. A version can be
                // ACTIVE and outside its window, and a chain that read only the
                // status would report a price on sale that nothing will sell.
                new SetupStep('published', self::anySellable($offers, $now), true, [
                    'count' => self::countSellable($offers, $now),
                ]),

                // Being sellable and being shown are two decisions (ADR-041).
                new SetupStep('advertised', self::anySellable($advertised, $now), true, [
                    'count' => count($advertised),
                ]),

                // Deployment configuration, and the one step no screen can fix.
                new SetupStep('payments', $paymentProvider !== null, true, $paymentProvider ?? []),
            ],
        ];
    }

    /**
     * @param list<OfferCandidate> $offers
     */
    private static function anySellable(array $offers, DateTimeImmutable $moment): bool
    {
        return self::countSellable($offers, $moment) > 0;
    }

    /**
     * @param list<OfferCandidate> $offers
     */
    private static function countSellable(array $offers, DateTimeImmutable $moment): int
    {
        $sellable = 0;

        foreach ($offers as $offer) {
            if ($offer->sellableAt($moment) instanceof OfferVersion) {
                ++$sellable;
            }
        }

        return $sellable;
    }
}
