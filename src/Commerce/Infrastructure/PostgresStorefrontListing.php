<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\StorefrontListing;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The storefront's listing decisions in PostgreSQL.
 *
 * Versions come back with drafts included, unlike the sale view: somebody
 * deciding whether to advertise an offer needs to see the version they are
 * about to put on a public page, and "what may be sold" would hide the one
 * being prepared.
 */
final class PostgresStorefrontListing implements StorefrontListing
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OfferVersionLoader $versions,
    ) {
    }

    public function offersOf(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.code, o.name, o.publicly_listed,
                       pl.id AS plan_id, pl.code AS plan_code,
                       pl.name AS plan_name, pl.rank AS plan_rank
                  FROM offers o
                  JOIN plans pl ON pl.id = o.plan_id
                 WHERE o.product_id = :productId
                 ORDER BY pl.rank, o.code
                SQL,
            ['productId' => $productId],
        );

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $versions = $this->versions->versionsOf($ids, false);

        return array_map(
            static fn (array $row): OfferCandidate => self::toCandidate($row, $versions),
            $rows,
        );
    }

    public function setPublicListing(string $productId, string $offerId, bool $listed): ?OfferCandidate
    {
        if (!Uuid::isValid($offerId)) {
            return null;
        }

        // RETURNING rather than an UPDATE followed by a read: the answer is
        // the row as it now stands, and a second query would answer about a
        // row somebody else may have changed in between.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE offers o
                   SET publicly_listed = :listed, updated_at = now()
                  FROM plans pl
                 WHERE o.id = :offerId
                   AND o.product_id = :productId
                   AND pl.id = o.plan_id
                RETURNING o.id, o.code, o.name, o.publicly_listed,
                          pl.id AS plan_id, pl.code AS plan_code,
                          pl.name AS plan_name, pl.rank AS plan_rank
                SQL,
            ['listed' => $listed, 'offerId' => $offerId, 'productId' => $productId],
            ['listed' => ParameterType::BOOLEAN],
        );

        if ($row === false) {
            return null;
        }

        $id = Row::string($row, 'id');

        return self::toCandidate($row, $this->versions->versionsOf([$id], false));
    }

    /**
     * @param array<string, mixed>              $row
     * @param array<string, list<OfferVersion>>  $versions
     */
    private static function toCandidate(array $row, array $versions): OfferCandidate
    {
        $id = Row::string($row, 'id');

        return new OfferCandidate(
            $id,
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            OfferVersionLoader::toPlan([
                'id' => $row['plan_id'] ?? null,
                'code' => $row['plan_code'] ?? null,
                'name' => $row['plan_name'] ?? null,
                'rank' => $row['plan_rank'] ?? null,
            ]),
            $versions[$id] ?? [],
            Row::boolean($row, 'publicly_listed'),
        );
    }
}
