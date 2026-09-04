<?php

declare(strict_types=1);

namespace App\Tax\Service;

use App\Product\Domain\ProductRegistry;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\SupplierTaxSettings;
use App\Tax\Domain\SupplyType;
use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\TaxRate;
use App\Tax\Domain\TaxRepository;
use App\Tax\Domain\TaxRule;
use App\Tax\Domain\VatNumberValidator;
use App\Tax\Domain\VatRegime;
use App\Tax\Domain\VatTransaction;
use DateTimeImmutable;

/**
 * The fiscal use cases (§25.3): who the customer is, what governs a sale, and
 * the fact left behind for the declaration.
 *
 * The chain runs one way and never the other:
 *
 *     profile + supply → rule → regime + place of taxation → rate at that
 *     date → calculation → VATTransaction
 *
 * The rate lookup happens *after* the regime is decided, not before. A regime
 * decides where a sale is taxed, and only then does the country and the date
 * pick the rate — which is why "the current rate for the customer's country"
 * is the wrong question and produces wrong invoices.
 */
final class Taxation
{
    /**
     * A missing rate is deliberately fatal rather than zero.
     *
     * Falling back to zero would invoice at no VAT and produce a fiscal fact
     * claiming a zero rate applied, which is a legal document asserting
     * something false. Refusing is recoverable; a wrong invoice with a legal
     * number is not, because it can only be corrected by a credit note.
     */
    public const NO_RATE = 'TAX_RATE_NOT_CONFIGURED';

    public function __construct(
        private readonly TaxRepository $tax,
        private readonly TaxRule $rule,
        private readonly VatNumberValidator $validator,
        private readonly ProductRegistry $products,
    ) {
    }

    public function profileFor(string $tenantId): CustomerTaxProfile
    {
        return $this->tax->findProfile($tenantId) ?? new CustomerTaxProfile(
            $tenantId,
            CustomerTaxProfile::B2C,
            null,
            false,
            [],
            null,
        );
    }

    /**
     * Saves the profile and, when a VAT number is supplied, verifies it.
     *
     * Verification happens here rather than lazily at invoicing time because
     * §25.3 wants the result stored **with its date** as audit evidence, and
     * because a customer who has just typed a number is the one person able
     * to correct it if it is wrong.
     *
     * @param array<string, mixed> $locationEvidence
     */
    public function saveProfile(
        string $tenantId,
        string $customerKind,
        ?string $countryCode,
        bool $taxablePerson,
        ?string $vatNumber,
        array $locationEvidence,
    ): CustomerTaxProfile {
        $this->tax->saveProfile(
            $tenantId,
            $customerKind,
            $countryCode === null ? null : strtoupper($countryCode),
            $taxablePerson,
            $locationEvidence,
        );

        if ($vatNumber !== null && $vatNumber !== '') {
            $normalised = self::normaliseVatNumber($vatNumber);
            $identification = $this->tax->saveIdentification(
                $tenantId,
                $normalised,
                substr($normalised, 0, 2),
            );

            // Fail-closed: whatever the validator says, only VALID becomes
            // VERIFIED. An outage leaves the number unproved rather than
            // trusted, and the evidence records that we asked.
            $this->tax->recordVerification($identification->id, $this->validator->check($normalised));
        }

        return $this->profileFor($tenantId);
    }

    /**
     * What would be applied, and why — with no side effect (§25.3).
     *
     * Deliberately the same code path invoicing uses. Two implementations of
     * "which regime applies" would drift, and the one that drifted would be
     * the one nobody could reproduce when a customer questioned an invoice.
     */
    public function calculate(
        string $tenantId,
        string $productId,
        int $amountMinorUnits,
        string $currency,
        ?string $supplyType,
        ?DateTimeImmutable $on,
    ): TaxCalculation {
        $profile = $this->profileFor($tenantId);
        $supplier = $this->supplierFor($productId);
        $moment = $on ?? new DateTimeImmutable();
        $supply = $supplyType ?? $supplier->defaultSupplyType;

        if (!SupplyType::isKnown($supply)) {
            $supply = $supplier->defaultSupplyType;
        }

        $decision = $this->rule->decide($supplier, $profile, $supply);

        $rate = 0;

        if ($decision->charges()) {
            $found = $this->tax->rateAt($decision->countryOfTaxation, TaxRate::STANDARD, $moment);

            if ($found === null) {
                // Naming the country and the date matters: "no rate" is
                // ambiguous, "no rate for DE on 2026-09-04" is actionable.
                throw new ConflictException(
                    self::NO_RATE,
                    sprintf(
                        'No standard VAT rate is configured for %s on %s.',
                        $decision->countryOfTaxation,
                        $moment->format('Y-m-d'),
                    ),
                    ['country' => $decision->countryOfTaxation, 'date' => $moment->format('Y-m-d')],
                );
            }

            $rate = $found->basisPoints;
        }

        $identification = $profile->identification;

        return new TaxCalculation(
            $decision->ruleId,
            $decision->regime,
            $decision->countryOfTaxation,
            $rate,
            $amountMinorUnits,
            TaxCalculation::vatOn($amountMinorUnits, $rate),
            strtoupper($currency),
            $decision->reverseCharge,
            $identification?->transactionStatus() ?? 'NONE',
            $identification?->vatNumber,
            $decision->legalMention,
            $decision->reasons,
        );
    }

    /**
     * Writes the fiscal facts of a document, inside the caller's transaction.
     *
     * @param list<TaxCalculation> $calculations
     * @return list<VatTransaction>
     */
    public function recordFor(
        string $tenantId,
        string $productId,
        ?string $invoiceId,
        ?string $creditNoteId,
        string $supplyType,
        DateTimeImmutable $transactionDate,
        array $calculations,
    ): array {
        return $this->tax->recordTransactions(
            $tenantId,
            $productId,
            $invoiceId,
            $creditNoteId,
            $supplyType,
            $transactionDate,
            $calculations,
        );
    }

    /**
     * @return array{transactions: list<VatTransaction>, total: int, limit: int, offset: int}
     */
    public function transactions(
        string $tenantId,
        string $productId,
        ?string $country,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        int $limit,
        int $offset,
    ): array {
        return [
            'transactions' => $this->tax->transactionsFor(
                $tenantId,
                $productId,
                $country,
                $from,
                $until,
                $limit,
                $offset,
            ),
            'total' => $this->tax->countTransactionsFor($tenantId, $productId, $country, $from, $until),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return list<TaxRate>
     */
    public function ratesOn(?DateTimeImmutable $moment): array
    {
        return $this->tax->ratesAt($moment ?? new DateTimeImmutable());
    }

    public function supplierFor(string $productId): SupplierTaxSettings
    {
        return SupplierTaxSettings::fromConfiguration(
            $this->products->configuration($productId),
            'FR',
        );
    }

    /**
     * The supply type a product sells by default, for callers that record a
     * fiscal fact without asking the customer what kind of supply it was.
     */
    public function defaultSupplyType(string $productId): string
    {
        return $this->supplierFor($productId)->defaultSupplyType;
    }

    /**
     * A VAT number, as a number: upper case, no spaces or punctuation.
     *
     * Two spellings of the same number must not both exist, and a number is
     * verified against a service that will not accept "FR 123 456".
     */
    public static function normaliseVatNumber(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    /**
     * The country a VAT prefix taxes in — GR for an EL number, XI for itself.
     */
    public static function countryOf(TaxIdentification $identification): string
    {
        return TaxIdentification::countryForPrefix($identification->countryPrefix);
    }

    /**
     * @return list<string>
     */
    public static function regimes(): array
    {
        return VatRegime::all();
    }

    public function requireProfile(string $tenantId): CustomerTaxProfile
    {
        $profile = $this->tax->findProfile($tenantId);

        if ($profile === null) {
            throw new NotFoundException('This tenant has no tax profile.', [], 'TAX_PROFILE_NOT_FOUND');
        }

        return $profile;
    }
}
