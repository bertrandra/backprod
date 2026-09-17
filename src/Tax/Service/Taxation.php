<?php

declare(strict_types=1);

namespace App\Tax\Service;

use App\Billing\Domain\InvoiceLine;
use App\Notification\Domain\Category;
use App\Notification\Service\Notifications;
use App\Product\Domain\ProductRegistry;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\RegimeDecision;
use App\Tax\Domain\SupplierTaxSettings;
use App\Tax\Domain\SupplyType;
use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\TaxRate;
use App\Tax\Domain\TaxRepository;
use App\Tax\Domain\TaxRule;
use App\Tax\Domain\VatNumberCheck;
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

    /**
     * The document charges something the regime no longer permits.
     *
     * Raised when a document was priced under one set of fiscal facts and is
     * being issued under another — a rate window opened in between, or the
     * customer's VAT number was verified after the quote was priced. Both are
     * ordinary, and both make the priced lines wrong rather than the decision
     * wrong. Refusing is recoverable; issuing is not, because the document
     * would carry a legal number and could only be corrected by a credit note.
     */
    public const TERMS_CHANGED = 'TAX_TERMS_CHANGED';

    /**
     * How long a verified VAT number is trusted before it is checked again.
     *
     * A verification is evidence with a date on it, not a permanent property:
     * a number can be withdrawn. Re-checking on every profile save would spend
     * the provider's rate limit on nothing, so it is re-checked when the
     * evidence gets old.
     */
    public const REVERIFY_AFTER_DAYS = 30;

    public function __construct(
        private readonly TaxRepository $tax,
        private readonly TaxRule $rule,
        private readonly VatNumberValidator $validator,
        private readonly ProductRegistry $products,
        private readonly Notifications $notifications,
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
        string $productId,
        string $actorUserId,
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
            //
            // Re-checked only when the evidence is missing or stale. Checking
            // on every save would spend the provider's rate limit to learn
            // nothing, and would overwrite the dated proof each time — §25.3
            // wants that proof kept, not refreshed for its own sake.
            if ($this->needsVerification($identification)) {
                $check = $this->validator->check($normalised);
                $this->tax->recordVerification($identification->id, $check);

                if (!$check->isValid()) {
                    $this->tellThemItIsNotProved($tenantId, $productId, $actorUserId, $identification, $check);
                }
            }
        }

        return $this->profileFor($tenantId);
    }

    /**
     * Tells the person who just typed the number that it did not prove out.
     *
     * Failing closed is right and it stays: an unproved number buys no reverse
     * charge, whether the provider said no or said nothing (R8). What was
     * missing is that nobody was told. The customer typed a VAT number
     * expecting to be zero-rated, gets charged standard VAT instead, and
     * finds out from an invoice — by which point it is a legal document that
     * cannot be quietly recomputed.
     *
     * So the refusal becomes visible while it is still fixable. `UNAVAILABLE`
     * is worth sending precisely because it is not the customer's fault: VIES
     * was unreachable, the number may well be good, and asking again later is
     * the whole remedy.
     *
     * Not `legalEffect`: this is operational, and the proof §25.3 actually
     * requires is the dated verification record, not this message about it.
     *
     * The dedup key carries the day, so a customer saving the same profile
     * four times in an afternoon is told once — and a re-check a month later
     * that fails again is told again, which is the point.
     */
    private function tellThemItIsNotProved(
        string $tenantId,
        string $productId,
        string $actorUserId,
        TaxIdentification $identification,
        VatNumberCheck $check,
    ): void {
        $this->notifications->raise(
            $tenantId,
            $productId,
            $actorUserId,
            'tax.vat_number_unverified',
            Category::BILLING,
            [
                'vat_number' => $identification->vatNumber,
                'outcome' => $check->outcome,
                'consequence' => 'standard_vat_applies',
            ],
            sprintf('%s:%s:%s', $identification->id, $check->outcome, date('Y-m-d')),
        );
    }

    /**
     * Whether a stored identification needs checking against the provider.
     *
     * Anything not verified does. A verification is evidence with a date on
     * it rather than a permanent property — a number can be withdrawn — so a
     * verified one is re-checked once its evidence gets old.
     */
    private function needsVerification(TaxIdentification $identification): bool
    {
        if (!$identification->isVerified() || $identification->verifiedAt === null) {
            return true;
        }

        $stale = $identification->verifiedAt->modify(sprintf('+%d days', self::REVERIFY_AFTER_DAYS));

        return $stale < new DateTimeImmutable();
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

        $rate = $this->rateFor($decision, $moment);

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
     * The fiscal facts a document's own lines produce, under a freshly decided
     * regime.
     *
     * The amounts come from the **lines**, never from a recalculation: §25.3
     * requires that the VAT transactions of an invoice sum to that invoice's
     * VAT, and the only way to guarantee that is to read what the document
     * actually charges. One fact per (rate, regime) pair, as §25.3 specifies.
     *
     * The regime, though, has to be decided now — and if it no longer matches
     * what the lines charge, that is a contradiction to refuse rather than a
     * number to reconcile. Called before the document is issued, so the
     * refusal costs nothing; after issue it would cost a credit note.
     *
     * @param list<InvoiceLine> $lines
     * @return list<TaxCalculation>
     */
    public function factsFor(
        string $tenantId,
        string $productId,
        array $lines,
        ?DateTimeImmutable $on,
    ): array {
        $profile = $this->profileFor($tenantId);
        $supplier = $this->supplierFor($productId);
        $moment = $on ?? new DateTimeImmutable();

        $decision = $this->rule->decide($supplier, $profile, $supplier->defaultSupplyType);
        $expected = $this->rateFor($decision, $moment);

        /** @var array<int, array{base: int, vat: int, currency: string}> $byRate */
        $byRate = [];

        foreach ($lines as $line) {
            $rate = $line->vatRateBasisPoints;

            if ($rate !== $expected) {
                throw new ConflictException(
                    self::TERMS_CHANGED,
                    'This document was priced under different tax terms and cannot be issued as it stands.',
                    [
                        'priced_rate_basis_points' => $rate,
                        'applicable_rate_basis_points' => $expected,
                        'regime' => $decision->regime,
                    ],
                );
            }

            $byRate[$rate] ??= ['base' => 0, 'vat' => 0, 'currency' => $line->net->currency];
            $byRate[$rate]['base'] += $line->net->minorUnits;
            $byRate[$rate]['vat'] += $line->vat->minorUnits;
        }

        $identification = $profile->identification;
        $facts = [];

        foreach ($byRate as $rate => $totals) {
            $facts[] = new TaxCalculation(
                $decision->ruleId,
                $decision->regime,
                $decision->countryOfTaxation,
                $rate,
                $totals['base'],
                $totals['vat'],
                $totals['currency'],
                $decision->reverseCharge,
                $identification?->transactionStatus() ?? 'NONE',
                $identification?->vatNumber,
                $decision->legalMention,
                $decision->reasons,
            );
        }

        return $facts;
    }

    /**
     * Writes the fiscal facts of a document.
     *
     * **Must be called inside the caller's transaction**, and opens none of
     * its own: an invoice with no VAT transaction is a document nothing will
     * declare, and a VAT transaction with no invoice declares something never
     * billed. Neither is observable if both are written together.
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
        ?string $productCode,
        ?string $country,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        int $limit,
        int $offset,
    ): array {
        return [
            'transactions' => $this->tax->transactionsFor(
                $tenantId,
                $productCode,
                $country,
                $from,
                $until,
                $limit,
                $offset,
            ),
            'total' => $this->tax->countTransactionsFor($tenantId, $productCode, $country, $from, $until),
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

    /**
     * The rate a decision implies at a moment: zero for the regimes that do
     * not charge, and otherwise the standard rate in force in the country of
     * taxation on that date.
     */
    private function rateFor(RegimeDecision $decision, DateTimeImmutable $moment): int
    {
        if (!$decision->charges()) {
            return 0;
        }

        $found = $this->tax->rateAt($decision->countryOfTaxation, TaxRate::STANDARD, $moment);

        if ($found === null) {
            // Naming the country and the date matters: "no rate" is ambiguous,
            // "no rate for DE on 2026-09-04" is actionable. Fatal rather than
            // zero: invoicing at no VAT would assert something false on a
            // document with a legal number.
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

        return $found->basisPoints;
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
