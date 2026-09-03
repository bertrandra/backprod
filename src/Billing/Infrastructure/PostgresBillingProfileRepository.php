<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\BillingProfile;
use App\Billing\Domain\BillingProfileRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class PostgresBillingProfileRepository implements BillingProfileRepository
{
    private const COLUMNS = <<<'SQL'
        tenant_id, legal_name, vat_number, registration_number,
        address_line1, address_line2, postal_code, city, country_code, billing_email
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $tenantId): ?BillingProfile
    {
        if (!Uuid::isValid($tenantId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM billing_profiles WHERE tenant_id = :tenantId',
            ['tenantId' => $tenantId],
        );

        return $row === false ? null : self::toProfile($row);
    }

    public function save(BillingProfile $profile): BillingProfile
    {
        // One row per tenant, upserted: a company has one legal identity, and
        // a second row would leave "which one gets invoiced" to whichever
        // query happened to run.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, vat_number, registration_number,
                     address_line1, address_line2, postal_code, city, country_code, billing_email)
                VALUES
                    (:tenantId, :legalName, :vatNumber, :registrationNumber,
                     :addressLine1, :addressLine2, :postalCode, :city, :countryCode, :billingEmail)
                ON CONFLICT (tenant_id) DO UPDATE SET
                    legal_name = EXCLUDED.legal_name,
                    vat_number = EXCLUDED.vat_number,
                    registration_number = EXCLUDED.registration_number,
                    address_line1 = EXCLUDED.address_line1,
                    address_line2 = EXCLUDED.address_line2,
                    postal_code = EXCLUDED.postal_code,
                    city = EXCLUDED.city,
                    country_code = EXCLUDED.country_code,
                    billing_email = EXCLUDED.billing_email,
                    updated_at = now()
                RETURNING
                SQL . ' ' . self::COLUMNS,
            [
                'tenantId' => $profile->tenantId,
                'legalName' => $profile->legalName,
                'vatNumber' => $profile->vatNumber,
                'registrationNumber' => $profile->registrationNumber,
                'addressLine1' => $profile->addressLine1,
                'addressLine2' => $profile->addressLine2,
                'postalCode' => $profile->postalCode,
                'city' => $profile->city,
                'countryCode' => $profile->countryCode,
                'billingEmail' => $profile->billingEmail,
            ],
        );

        if ($row === false) {
            throw new RuntimeException('Failed to save a billing profile.');
        }

        return self::toProfile($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toProfile(array $row): BillingProfile
    {
        return new BillingProfile(
            Row::string($row, 'tenant_id'),
            Row::string($row, 'legal_name'),
            Row::nullableString($row, 'vat_number'),
            Row::nullableString($row, 'registration_number'),
            Row::nullableString($row, 'address_line1'),
            Row::nullableString($row, 'address_line2'),
            Row::nullableString($row, 'postal_code'),
            Row::nullableString($row, 'city'),
            Row::nullableString($row, 'country_code'),
            Row::nullableString($row, 'billing_email'),
        );
    }
}
