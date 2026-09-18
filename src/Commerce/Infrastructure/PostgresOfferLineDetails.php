<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Billing\Domain\LineOffer;
use App\Commerce\Domain\OfferLineDetails;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PostgresOfferLineDetails implements OfferLineDetails
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function describe(array $versionIds): array
    {
        $ids = array_values(array_unique(array_filter($versionIds, static fn (string $id): bool => Uuid::isValid($id))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.id, v.version, v.billing_period,
                       o.code AS offer_code, o.name AS offer_name,
                       pl.name AS plan_name,
                       pr.code AS product_code, pr.name AS product_name
                  FROM offer_versions v
                  JOIN offers o ON o.id = v.offer_id
                  JOIN plans pl ON pl.id = o.plan_id
                  JOIN products pr ON pr.id = o.product_id
                 WHERE v.id IN (:ids)
                SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $details = [];

        foreach ($rows as $row) {
            $details[Row::string($row, 'id')] = new LineOffer(
                Row::string($row, 'product_code'),
                Row::string($row, 'product_name'),
                Row::string($row, 'offer_code'),
                Row::string($row, 'offer_name'),
                Row::string($row, 'plan_name'),
                Row::string($row, 'billing_period'),
                Row::integer($row, 'version'),
            );
        }

        return $details;
    }
}
