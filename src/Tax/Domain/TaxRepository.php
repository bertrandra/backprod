<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * Storage for the fiscal module (§25.3).
 *
 * `recordTransactions` is a *participating* method: it takes no transaction
 * of its own and is called inside the caller's, because a fiscal fact and the
 * invoice that produced it must commit together or not at all. An invoice
 * with no VAT transaction is a document nothing will declare; a VAT
 * transaction with no invoice is a declaration of something that was never
 * billed. This is the same shape as `applyActivate`, `applyIssue` and
 * `applyCompleteOrder` elsewhere in the platform, and for the same reason:
 * no code in this repository nests `$connection->transactional()`.
 */
interface TaxRepository
{
    public function findProfile(string $tenantId): ?CustomerTaxProfile;

    /**
     * @param array<string, mixed> $locationEvidence
     */
    public function saveProfile(
        string $tenantId,
        string $customerKind,
        ?string $countryCode,
        bool $taxablePerson,
        array $locationEvidence,
    ): CustomerTaxProfile;

    public function saveIdentification(
        string $tenantId,
        string $vatNumber,
        string $countryPrefix,
    ): TaxIdentification;

    public function recordVerification(
        string $identificationId,
        VatNumberCheck $check,
    ): TaxIdentification;

    /**
     * The rate in force in a country on a date — never "the current rate".
     */
    public function rateAt(string $countryCode, string $rateKind, DateTimeImmutable $moment): ?TaxRate;

    /**
     * @return list<TaxRate>
     */
    public function ratesAt(DateTimeImmutable $moment): array;

    /**
     * Writes the fiscal facts of one document, inside the caller's
     * transaction.
     *
     * @param list<TaxCalculation> $calculations
     * @return list<VatTransaction>
     */
    public function recordTransactions(
        string $tenantId,
        string $productId,
        ?string $invoiceId,
        ?string $creditNoteId,
        string $supplyType,
        DateTimeImmutable $transactionDate,
        array $calculations,
    ): array;

    /**
     * @return list<VatTransaction>
     */
    public function transactionsFor(
        string $tenantId,
        string $productId,
        ?string $country,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        int $limit,
        int $offset,
    ): array;

    public function countTransactionsFor(
        string $tenantId,
        string $productId,
        ?string $country,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
    ): int;

    /**
     * @return list<VatReportingPeriod>
     */
    public function periods(?string $jurisdiction): array;

    public function findPeriod(string $periodId): ?VatReportingPeriod;

    public function openPeriod(
        string $jurisdiction,
        string $periodKind,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $endsOn,
    ): VatReportingPeriod;

    /**
     * Totals for a period, computed from the fiscal facts it covers.
     *
     * @return array{
     *     currency: string,
     *     currencies: list<string>,
     *     total_base: int,
     *     total_vat: int,
     *     transaction_count: int,
     *     breakdown: list<array{
     *         regime: string, rate: int, currency: string, base: int, vat: int, count: int
     *     }>
     * }
     */
    public function totalsFor(VatReportingPeriod $period): array;

    /**
     * Closes a period and freezes its declaration, in one transaction.
     *
     * Closing without writing the declaration would leave a period that can
     * never be modified and never says what it declared.
     */
    public function close(VatReportingPeriod $period, ?string $actorUserId): VatDeclaration;

    public function declarationFor(string $periodId): ?VatDeclaration;
}
