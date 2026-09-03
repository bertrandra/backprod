<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The catalogue in PostgreSQL.
 *
 * Offers, their versions and their grants are three queries, not one query
 * per offer. A catalogue is read on every pricing page, and an N+1 here would
 * be paid on the request that decides whether someone buys.
 *
 * The version filter is deliberately coarse — status only. Whether a version
 * may be sold *now* is a question about the clock, and OfferVersion answers
 * it; putting `now()` in this SQL as well would give the same question two
 * implementations that agree until a boundary nobody tests.
 */
final class PostgresCatalogueRepository implements CatalogueRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function plansFor(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, code, name, rank
                FROM plans
                WHERE product_id = :productId
                ORDER BY rank, code
                SQL,
            ['productId' => $productId],
        );

        return array_map(self::toPlan(...), $rows);
    }

    public function featuresFor(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, code, name, kind, unit
                FROM features
                WHERE product_id = :productId
                ORDER BY code
                SQL,
            ['productId' => $productId],
        );

        return array_map(self::toFeature(...), $rows);
    }

    public function offersFor(string $productId): array
    {
        return $this->load($productId, null);
    }

    public function findOffer(string $productId, string $offerId): ?OfferCandidate
    {
        if (!Uuid::isValid($offerId)) {
            return null;
        }

        return $this->load($productId, $offerId)[0] ?? null;
    }

    /**
     * @return list<OfferCandidate>
     */
    private function load(string $productId, ?string $offerId): array
    {
        // The optional filter is added rather than expressed as a null-check
        // on a bound parameter: that form needs the same placeholder twice
        // and a cast to give it a type, and both are avoidable here.
        $conditions = 'o.product_id = :productId';
        $parameters = ['productId' => $productId];

        if ($offerId !== null) {
            $conditions .= ' AND o.id = :offerId';
            $parameters['offerId'] = $offerId;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT o.id, o.code, o.name,
                       pl.id AS plan_id, pl.code AS plan_code, pl.name AS plan_name, pl.rank AS plan_rank
                FROM offers o
                JOIN plans pl ON pl.id = o.plan_id
                WHERE {$conditions}
                ORDER BY pl.rank, o.code
                SQL,
            $parameters,
        );

        if ($rows === []) {
            return [];
        }

        $offerIds = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $versions = $this->versionsOf($offerIds);

        return array_map(
            static function (array $row) use ($versions): OfferCandidate {
                $id = Row::string($row, 'id');

                return new OfferCandidate(
                    $id,
                    Row::string($row, 'code'),
                    Row::string($row, 'name'),
                    new Plan(
                        Row::string($row, 'plan_id'),
                        Row::string($row, 'plan_code'),
                        Row::string($row, 'plan_name'),
                        Row::integer($row, 'plan_rank'),
                    ),
                    $versions[$id] ?? [],
                );
            },
            $rows,
        );
    }

    /**
     * @param list<string> $offerIds
     *
     * @return array<string, list<OfferVersion>>
     */
    private function versionsOf(array $offerIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, offer_id, version, status, billing_period,
                       price_minor_units, currency, valid_from, valid_until
                FROM offer_versions
                WHERE offer_id IN (:offerIds)
                  AND status = 'ACTIVE'
                ORDER BY offer_id, version DESC
                SQL,
            ['offerIds' => $offerIds],
            ['offerIds' => ArrayParameterType::STRING],
        );

        if ($rows === []) {
            return [];
        }

        $grants = $this->grantsOf(
            array_map(static fn (array $row): string => Row::string($row, 'id'), $rows),
        );

        $versions = [];

        foreach ($rows as $row) {
            $id = Row::string($row, 'id');

            // Grouped by offer, and already newest-first from the ORDER BY.
            $versions[Row::string($row, 'offer_id')][] = new OfferVersion(
                $id,
                Row::integer($row, 'version'),
                Row::string($row, 'status'),
                Row::string($row, 'billing_period'),
                Row::integer($row, 'price_minor_units'),
                Row::string($row, 'currency'),
                Row::timestamp($row, 'valid_from'),
                Row::nullableTimestamp($row, 'valid_until'),
                $grants[$id] ?? [],
            );
        }

        return $versions;
    }

    /**
     * @param list<string> $versionIds
     *
     * @return array<string, list<OfferGrant>>
     */
    private function grantsOf(array $versionIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ovf.offer_version_id, ovf.limit_value,
                       f.id, f.code, f.name, f.kind, f.unit
                FROM offer_version_features ovf
                JOIN features f ON f.id = ovf.feature_id
                WHERE ovf.offer_version_id IN (:versionIds)
                ORDER BY f.code
                SQL,
            ['versionIds' => $versionIds],
            ['versionIds' => ArrayParameterType::STRING],
        );

        $grants = [];

        foreach ($rows as $row) {
            $grants[Row::string($row, 'offer_version_id')][] = new OfferGrant(
                self::toFeature($row),
                Row::nullableInteger($row, 'limit_value'),
            );
        }

        return $grants;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toPlan(array $row): Plan
    {
        return new Plan(
            Row::string($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::integer($row, 'rank'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toFeature(array $row): Feature
    {
        return new Feature(
            Row::string($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::string($row, 'kind'),
            Row::nullableString($row, 'unit'),
        );
    }
}
