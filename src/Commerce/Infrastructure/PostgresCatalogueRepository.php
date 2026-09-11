<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
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
    public function __construct(
        private readonly Connection $connection,
        private readonly OfferVersionLoader $versions,
    ) {
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

        return array_map(OfferVersionLoader::toPlan(...), $rows);
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

        return array_map(OfferVersionLoader::toFeature(...), $rows);
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

    public function publiclyListedOffersFor(string $productId): array
    {
        return $this->load($productId, null, true);
    }

    public function findPubliclyListedOffer(string $productId, string $offerId): ?OfferCandidate
    {
        if (!Uuid::isValid($offerId)) {
            return null;
        }

        return $this->load($productId, $offerId, true)[0] ?? null;
    }

    public function findOfferByVersion(string $productId, string $offerVersionId): ?OfferCandidate
    {
        if (!Uuid::isValid($offerVersionId)) {
            return null;
        }

        $offerId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT v.offer_id
                  FROM offer_versions v
                  JOIN offers o ON o.id = v.offer_id
                 WHERE v.id = :versionId AND o.product_id = :productId
                SQL,
            ['versionId' => $offerVersionId, 'productId' => $productId],
        );

        if (!is_string($offerId)) {
            return null;
        }

        $candidate = $this->load($productId, $offerId)[0] ?? null;

        if ($candidate === null) {
            return null;
        }

        // Carrying the one version asked for, whatever its status: `load`
        // deliberately sees only what is sellable, and this question is about
        // what was sold. A version withdrawn since is still the terms the
        // document was written against.
        return new OfferCandidate(
            $candidate->id,
            $candidate->code,
            $candidate->name,
            $candidate->plan,
            $this->versionById($offerVersionId),
            $candidate->publiclyListed,
        );
    }

    /**
     * @return list<OfferVersion>
     */
    private function versionById(string $offerVersionId): array
    {
        $version = $this->versions->byId($offerVersionId);

        return $version === null ? [] : [$version];
    }

    /**
     * @return list<OfferCandidate>
     */
    private function load(string $productId, ?string $offerId, bool $publiclyListedOnly = false): array
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

        // Narrowed here rather than after the fetch, so a request with no
        // session behind it never pulls a private price out of storage at
        // all. `offers_publicly_listed_idx` is the partial index this hits.
        if ($publiclyListedOnly) {
            $conditions .= ' AND o.publicly_listed';
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT o.id, o.code, o.name, o.publicly_listed,
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
        $versions = $this->versions->versionsOf($offerIds, true);

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
                    Row::boolean($row, 'publicly_listed'),
                );
            },
            $rows,
        );
    }
}
