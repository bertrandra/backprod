<?php

declare(strict_types=1);

namespace App\Tax\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\TaxRate;
use App\Tax\Domain\TaxRepository;
use App\Tax\Domain\VatDeclaration;
use App\Tax\Domain\VatNumberCheck;
use App\Tax\Domain\VatReportingPeriod;
use App\Tax\Domain\VatTransaction;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class PostgresTaxRepository implements TaxRepository
{
    private const PROFILE_COLUMNS = 'tenant_id, customer_kind, country_code, taxable_person, location_evidence';

    private const IDENT_COLUMNS = <<<'SQL'
        id, tenant_id, vat_number, country_prefix, status,
        verified_at, verification_source, verification_result
        SQL;

    private const RATE_COLUMNS = 'id, country_code, rate_kind, basis_points, valid_from, valid_until, source';

    private const TRANSACTION_COLUMNS = <<<'SQL'
        id, invoice_id, credit_note_id, tenant_id, product_id, country,
        customer_tax_number, customer_tax_status, supply_type,
        taxable_base, vat_rate, vat_amount, currency,
        vat_regime, rule_id, reverse_charge, transaction_date
        SQL;

    private const PERIOD_COLUMNS = <<<'SQL'
        id, jurisdiction, period_kind, starts_on, ends_on, status, closed_at, closed_by
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findProfile(string $tenantId): ?CustomerTaxProfile
    {
        if (!Uuid::isValid($tenantId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::PROFILE_COLUMNS . ' FROM customer_tax_profiles WHERE tenant_id = :tenantId',
            ['tenantId' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return self::toProfile($row, $this->findIdentification($tenantId));
    }

    /**
     * @param array<string, mixed> $locationEvidence
     */
    public function saveProfile(
        string $tenantId,
        string $customerKind,
        ?string $countryCode,
        bool $taxablePerson,
        array $locationEvidence,
    ): CustomerTaxProfile {
        // One fiscal profile per tenant. A second row would leave "which
        // status governs this sale" to whichever query ran.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            INSERT INTO customer_tax_profiles
                (tenant_id, customer_kind, country_code, taxable_person, location_evidence)
            VALUES (:tenantId, :kind, :country, :taxable, CAST(:evidence AS jsonb))
            ON CONFLICT (tenant_id) DO UPDATE SET
                customer_kind = EXCLUDED.customer_kind,
                country_code = EXCLUDED.country_code,
                taxable_person = EXCLUDED.taxable_person,
                location_evidence = EXCLUDED.location_evidence,
                updated_at = now()
            RETURNING
            SQL . ' ' . self::PROFILE_COLUMNS,
            [
                'tenantId' => $tenantId,
                'kind' => $customerKind,
                'country' => $countryCode,
                'taxable' => $taxablePerson ? 'true' : 'false',
                'evidence' => json_encode($locationEvidence, JSON_THROW_ON_ERROR),
            ],
        );

        if ($row === false) {
            throw new RuntimeException('The tax profile could not be saved.');
        }

        return self::toProfile($row, $this->findIdentification($tenantId));
    }

    public function saveIdentification(
        string $tenantId,
        string $vatNumber,
        string $countryPrefix,
    ): TaxIdentification {
        // A changed number is an unverified number. Keeping the old
        // verification would let a customer verify one number and invoice
        // under another, which is the whole attack the verification exists
        // to stop.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            INSERT INTO tax_identifications (tenant_id, vat_number, country_prefix, status)
            VALUES (:tenantId, :number, :prefix, 'UNVERIFIED')
            ON CONFLICT (tenant_id, vat_number) DO UPDATE SET
                country_prefix = EXCLUDED.country_prefix,
                updated_at = now()
            RETURNING
            SQL . ' ' . self::IDENT_COLUMNS,
            ['tenantId' => $tenantId, 'number' => $vatNumber, 'prefix' => $countryPrefix],
        );

        if ($row === false) {
            throw new RuntimeException('The VAT number could not be saved.');
        }

        return self::toIdentification($row);
    }

    public function recordVerification(string $identificationId, VatNumberCheck $check): TaxIdentification
    {
        $status = $check->identificationStatus();

        // A date exactly when there is a verdict. UNAVAILABLE is not a
        // verdict — we asked and got no answer — so it carries no date, and
        // the CHECK constraint enforces the pairing either way.
        $dated = in_array($status, [TaxIdentification::VERIFIED, TaxIdentification::INVALID], true);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            UPDATE tax_identifications
               SET status = :status,
                   verified_at = CASE WHEN :dated THEN now() ELSE NULL END,
                   verification_source = :source,
                   verification_result = CAST(:result AS jsonb),
                   updated_at = now()
             WHERE id = :id
            RETURNING
            SQL . ' ' . self::IDENT_COLUMNS,
            [
                'id' => $identificationId,
                'status' => $status,
                'dated' => $dated ? 'true' : 'false',
                'source' => $check->outcome === VatNumberCheck::UNAVAILABLE ? null : 'VIES',
                'result' => json_encode([
                    'outcome' => $check->outcome,
                    'registered_name' => $check->registeredName,
                    'registered_address' => $check->registeredAddress,
                    'evidence' => $check->evidence,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        if ($row === false) {
            throw new RuntimeException('The verification could not be recorded.');
        }

        return self::toIdentification($row);
    }

    public function rateAt(string $countryCode, string $rateKind, DateTimeImmutable $moment): ?TaxRate
    {
        // The window decides, not a "current" flag. The exclusion constraint
        // guarantees at most one row can match, so LIMIT 1 is a formality
        // rather than a tie-break hiding an ambiguity.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::RATE_COLUMNS . <<<'SQL'
              FROM tax_rates
             WHERE country_code = :country
               AND rate_kind = :kind
               AND valid_from <= :moment
               AND (valid_until IS NULL OR valid_until > :moment)
             LIMIT 1
            SQL,
            [
                'country' => strtoupper($countryCode),
                'kind' => $rateKind,
                'moment' => self::moment($moment),
            ],
        );

        return $row === false ? null : self::toRate($row);
    }

    /**
     * @return list<TaxRate>
     */
    public function ratesAt(DateTimeImmutable $moment): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::RATE_COLUMNS . <<<'SQL'
              FROM tax_rates
             WHERE valid_from <= :moment
               AND (valid_until IS NULL OR valid_until > :moment)
             ORDER BY country_code, rate_kind
            SQL,
            ['moment' => self::moment($moment)],
        );

        return array_map(self::toRate(...), $rows);
    }

    /**
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
    ): array {
        // No transactional() here on purpose: this participates in the
        // caller's transaction so the fiscal facts and the invoice that
        // produced them commit together.
        $written = [];

        foreach ($calculations as $calculation) {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                INSERT INTO vat_transactions
                    (invoice_id, credit_note_id, tenant_id, product_id, country,
                     customer_tax_number, customer_tax_status, supply_type,
                     taxable_base, vat_rate, vat_amount, currency,
                     vat_regime, rule_id, reverse_charge, transaction_date)
                VALUES
                    (:invoiceId, :creditNoteId, :tenantId, :productId, :country,
                     :taxNumber, :taxStatus, :supplyType,
                     :base, :rate, :vat, :currency,
                     :regime, :ruleId, :reverseCharge, :date)
                RETURNING
                SQL . ' ' . self::TRANSACTION_COLUMNS,
                [
                    'invoiceId' => $invoiceId,
                    'creditNoteId' => $creditNoteId,
                    'tenantId' => $tenantId,
                    'productId' => $productId,
                    'country' => $calculation->countryOfTaxation,
                    'taxNumber' => $calculation->customerTaxNumber,
                    'taxStatus' => $calculation->customerTaxStatus,
                    'supplyType' => $supplyType,
                    'base' => $calculation->taxableBase,
                    'rate' => $calculation->rateBasisPoints,
                    'vat' => $calculation->vatAmount,
                    'currency' => $calculation->currency,
                    'regime' => $calculation->regime,
                    'ruleId' => $calculation->ruleId,
                    'reverseCharge' => $calculation->reverseCharge ? 'true' : 'false',
                    'date' => self::moment($transactionDate),
                ],
            );

            if ($row === false) {
                throw new RuntimeException('The fiscal fact could not be recorded.');
            }

            $written[] = self::toTransaction($row);
        }

        return $written;
    }

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
    ): array {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::TRANSACTION_COLUMNS . <<<'SQL'
              FROM vat_transactions
             WHERE tenant_id = :tenantId
               AND product_id = :productId
               AND (CAST(:country AS TEXT) IS NULL OR country = CAST(:country AS TEXT))
               AND (CAST(:from AS TIMESTAMPTZ) IS NULL OR transaction_date >= CAST(:from AS TIMESTAMPTZ))
               AND (CAST(:until AS TIMESTAMPTZ) IS NULL OR transaction_date < CAST(:until AS TIMESTAMPTZ))
             ORDER BY transaction_date DESC, created_at DESC
             LIMIT :limit OFFSET :offset
            SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'country' => $country === null ? null : strtoupper($country),
                'from' => self::moment($from),
                'until' => self::moment($until),
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        return array_map(self::toTransaction(...), $rows);
    }

    public function countTransactionsFor(
        string $tenantId,
        string $productId,
        ?string $country,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
    ): int {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            <<<'SQL'
            SELECT count(*) FROM vat_transactions
             WHERE tenant_id = :tenantId
               AND product_id = :productId
               AND (CAST(:country AS TEXT) IS NULL OR country = CAST(:country AS TEXT))
               AND (CAST(:from AS TIMESTAMPTZ) IS NULL OR transaction_date >= CAST(:from AS TIMESTAMPTZ))
               AND (CAST(:until AS TIMESTAMPTZ) IS NULL OR transaction_date < CAST(:until AS TIMESTAMPTZ))
            SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'country' => $country === null ? null : strtoupper($country),
                'from' => self::moment($from),
                'until' => self::moment($until),
            ],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<VatReportingPeriod>
     */
    public function periods(?string $jurisdiction): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::PERIOD_COLUMNS . <<<'SQL'
              FROM vat_reporting_periods
             WHERE (CAST(:jurisdiction AS TEXT) IS NULL OR jurisdiction = CAST(:jurisdiction AS TEXT))
             ORDER BY starts_on DESC
            SQL,
            ['jurisdiction' => $jurisdiction === null ? null : strtoupper($jurisdiction)],
        );

        return array_map(self::toPeriod(...), $rows);
    }

    public function findPeriod(string $periodId): ?VatReportingPeriod
    {
        if (!Uuid::isValid($periodId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::PERIOD_COLUMNS . ' FROM vat_reporting_periods WHERE id = :id',
            ['id' => $periodId],
        );

        return $row === false ? null : self::toPeriod($row);
    }

    public function openPeriod(
        string $jurisdiction,
        string $periodKind,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $endsOn,
    ): VatReportingPeriod {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            INSERT INTO vat_reporting_periods (jurisdiction, period_kind, starts_on, ends_on)
            VALUES (:jurisdiction, :kind, :startsOn, :endsOn)
            ON CONFLICT (jurisdiction, starts_on, ends_on) DO UPDATE
                SET jurisdiction = EXCLUDED.jurisdiction
            RETURNING
            SQL . ' ' . self::PERIOD_COLUMNS,
            [
                'jurisdiction' => strtoupper($jurisdiction),
                'kind' => $periodKind,
                'startsOn' => $startsOn->format('Y-m-d'),
                'endsOn' => $endsOn->format('Y-m-d'),
            ],
        );

        if ($row === false) {
            throw new RuntimeException('The reporting period could not be opened.');
        }

        return self::toPeriod($row);
    }

    /**
     * @return array{
     *     currency: string,
     *     total_base: int,
     *     total_vat: int,
     *     transaction_count: int,
     *     breakdown: list<array{regime: string, rate: int, base: int, vat: int, count: int}>
     * }
     */
    public function totalsFor(VatReportingPeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT vat_regime, vat_rate, currency,
                   sum(taxable_base) AS base,
                   sum(vat_amount) AS vat,
                   count(*) AS entries
              FROM vat_transactions
             WHERE country = :jurisdiction
               AND transaction_date >= :startsOn
               AND transaction_date < (CAST(:endsOn AS DATE) + INTERVAL '1 day')
             GROUP BY vat_regime, vat_rate, currency
             ORDER BY vat_regime, vat_rate
            SQL,
            [
                'jurisdiction' => $period->jurisdiction,
                'startsOn' => $period->startsOn->format('Y-m-d'),
                'endsOn' => $period->endsOn->format('Y-m-d'),
            ],
        );

        $breakdown = [];
        $totalBase = 0;
        $totalVat = 0;
        $count = 0;
        $currency = 'EUR';

        foreach ($rows as $row) {
            $base = (int) Row::string($row, 'base');
            $vat = (int) Row::string($row, 'vat');
            $entries = (int) Row::string($row, 'entries');
            $currency = Row::string($row, 'currency');

            $breakdown[] = [
                'regime' => Row::string($row, 'vat_regime'),
                'rate' => Row::integer($row, 'vat_rate'),
                'base' => $base,
                'vat' => $vat,
                'count' => $entries,
            ];

            $totalBase += $base;
            $totalVat += $vat;
            $count += $entries;
        }

        return [
            'currency' => $currency,
            'total_base' => $totalBase,
            'total_vat' => $totalVat,
            'transaction_count' => $count,
            'breakdown' => $breakdown,
        ];
    }

    public function close(VatReportingPeriod $period, ?string $actorUserId): VatDeclaration
    {
        // The declaration is written and the period closed in one
        // transaction. Closing first would leave a period that can never be
        // modified and never says what it declared — the trigger makes that
        // state permanent.
        return $this->connection->transactional(function () use ($period, $actorUserId): VatDeclaration {
            $totals = $this->totalsFor($period);

            $declaration = $this->connection->fetchAssociative(
                <<<'SQL'
                INSERT INTO vat_declarations
                    (period_id, currency, total_base, total_vat, breakdown, transaction_count)
                VALUES (:periodId, :currency, :base, :vat, CAST(:breakdown AS jsonb), :count)
                RETURNING id, period_id, currency, total_base, total_vat, breakdown, transaction_count, created_at
                SQL,
                [
                    'periodId' => $period->id,
                    'currency' => $totals['currency'],
                    'base' => $totals['total_base'],
                    'vat' => $totals['total_vat'],
                    'breakdown' => json_encode($totals['breakdown'], JSON_THROW_ON_ERROR),
                    'count' => $totals['transaction_count'],
                ],
            );

            if ($declaration === false) {
                throw new RuntimeException('The declaration could not be written.');
            }

            $this->connection->executeStatement(
                <<<'SQL'
                UPDATE vat_reporting_periods
                   SET status = 'CLOSED', closed_at = now(), closed_by = :actor
                 WHERE id = :id
                SQL,
                ['id' => $period->id, 'actor' => $actorUserId],
            );

            return self::toDeclaration($declaration);
        });
    }

    public function declarationFor(string $periodId): ?VatDeclaration
    {
        if (!Uuid::isValid($periodId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, period_id, currency, total_base, total_vat, breakdown, transaction_count, created_at
              FROM vat_declarations WHERE period_id = :periodId
            SQL,
            ['periodId' => $periodId],
        );

        return $row === false ? null : self::toDeclaration($row);
    }

    private function findIdentification(string $tenantId): ?TaxIdentification
    {
        // The most recently touched number is the one that governs. A tenant
        // that corrects a typo leaves the old row behind as history rather
        // than losing the verification audit trail.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::IDENT_COLUMNS . <<<'SQL'
              FROM tax_identifications
             WHERE tenant_id = :tenantId
             ORDER BY updated_at DESC
             LIMIT 1
            SQL,
            ['tenantId' => $tenantId],
        );

        return $row === false ? null : self::toIdentification($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toProfile(array $row, ?TaxIdentification $identification): CustomerTaxProfile
    {
        $evidence = $row['location_evidence'] ?? null;
        $decoded = is_string($evidence) ? json_decode($evidence, true) : null;

        /** @var array<string, mixed> $locationEvidence */
        $locationEvidence = is_array($decoded) ? $decoded : [];

        return new CustomerTaxProfile(
            Row::string($row, 'tenant_id'),
            Row::string($row, 'customer_kind'),
            Row::nullableString($row, 'country_code'),
            self::boolean($row, 'taxable_person'),
            $locationEvidence,
            $identification,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toIdentification(array $row): TaxIdentification
    {
        $result = $row['verification_result'] ?? null;
        $decoded = is_string($result) ? json_decode($result, true) : null;

        /** @var array<string, mixed>|null $verification */
        $verification = is_array($decoded) ? $decoded : null;

        return new TaxIdentification(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'vat_number'),
            Row::string($row, 'country_prefix'),
            Row::string($row, 'status'),
            Row::nullableTimestamp($row, 'verified_at'),
            Row::nullableString($row, 'verification_source'),
            $verification,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRate(array $row): TaxRate
    {
        return new TaxRate(
            Row::string($row, 'id'),
            Row::string($row, 'country_code'),
            Row::string($row, 'rate_kind'),
            Row::integer($row, 'basis_points'),
            Row::timestamp($row, 'valid_from'),
            Row::nullableTimestamp($row, 'valid_until'),
            Row::nullableString($row, 'source'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toTransaction(array $row): VatTransaction
    {
        return new VatTransaction(
            Row::string($row, 'id'),
            Row::nullableString($row, 'invoice_id'),
            Row::nullableString($row, 'credit_note_id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'country'),
            Row::nullableString($row, 'customer_tax_number'),
            Row::string($row, 'customer_tax_status'),
            Row::string($row, 'supply_type'),
            Row::integer($row, 'taxable_base'),
            Row::integer($row, 'vat_rate'),
            Row::integer($row, 'vat_amount'),
            Row::string($row, 'currency'),
            Row::string($row, 'vat_regime'),
            Row::string($row, 'rule_id'),
            self::boolean($row, 'reverse_charge'),
            Row::timestamp($row, 'transaction_date'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toPeriod(array $row): VatReportingPeriod
    {
        return new VatReportingPeriod(
            Row::string($row, 'id'),
            Row::string($row, 'jurisdiction'),
            Row::string($row, 'period_kind'),
            new DateTimeImmutable(Row::string($row, 'starts_on')),
            new DateTimeImmutable(Row::string($row, 'ends_on')),
            Row::string($row, 'status'),
            Row::nullableTimestamp($row, 'closed_at'),
            Row::nullableString($row, 'closed_by'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toDeclaration(array $row): VatDeclaration
    {
        $breakdown = $row['breakdown'] ?? null;
        $decoded = is_string($breakdown) ? json_decode($breakdown, true) : null;

        /** @var list<array{regime: string, rate: int, base: int, vat: int, count: int}> $lines */
        $lines = is_array($decoded) ? array_values($decoded) : [];

        return new VatDeclaration(
            Row::string($row, 'id'),
            Row::string($row, 'period_id'),
            Row::string($row, 'currency'),
            Row::integer($row, 'total_base'),
            Row::integer($row, 'total_vat'),
            $lines,
            Row::integer($row, 'transaction_count'),
            Row::timestamp($row, 'created_at'),
        );
    }

    /**
     * PostgreSQL hands booleans back as 't' through some drivers and as a
     * real bool through others, so both are accepted rather than one being
     * assumed.
     *
     * @param array<string, mixed> $row
     */
    private static function boolean(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    private static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s.uP');
    }
}
