<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\SubscriptionTerms;
use App\Shared\Database\Row;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Rows to versions, for the two repositories that both need it.
 *
 * Reading the catalogue and writing it ask different questions — one hides
 * drafts because they are not for sale, the other shows them because they
 * are the thing being edited — but the mapping from a row to an
 * {@see OfferVersion} is the same mapping, and two copies of it would be two
 * places for a column added later to be forgotten.
 *
 * The one difference is a parameter rather than a second class: whether
 * unpublished versions are included.
 */
final class OfferVersionLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $offerIds
     *
     * @return array<string, list<OfferVersion>> keyed by offer id, newest version first
     */
    public function versionsOf(array $offerIds, bool $publishedOnly): array
    {
        if ($offerIds === []) {
            return [];
        }

        $published = $publishedOnly ? "AND status = 'ACTIVE'" : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, offer_id, version, status, billing_period,
                       price_minor_units, currency, valid_from, valid_until,
                       term_months, commitment_months, cancellation_policy,
                       renewal, early_termination, notice_days
                  FROM offer_versions
                 WHERE offer_id IN (:offerIds)
                       {$published}
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
                new SubscriptionTerms(
                    Row::nullableInteger($row, 'term_months'),
                    Row::integer($row, 'commitment_months'),
                    Row::string($row, 'cancellation_policy'),
                    Row::string($row, 'renewal'),
                    Row::string($row, 'early_termination'),
                    Row::integer($row, 'notice_days'),
                ),
            );
        }

        return $versions;
    }

    /**
     * One version by id, with no filter on status and none on the clock.
     *
     * This is what answers "what did they buy?" — a question a withdrawn or
     * expired version must still answer, since an invoice raised against it
     * does not stop being owed.
     */
    public function byId(string $versionId): ?OfferVersion
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, offer_id, version, status, billing_period,
                       price_minor_units, currency, valid_from, valid_until,
                       term_months, commitment_months, cancellation_policy,
                       renewal, early_termination, notice_days
                  FROM offer_versions
                 WHERE id = :versionId
                SQL,
            ['versionId' => $versionId],
        );

        if ($row === false) {
            return null;
        }

        $id = Row::string($row, 'id');

        return new OfferVersion(
            $id,
            Row::integer($row, 'version'),
            Row::string($row, 'status'),
            Row::string($row, 'billing_period'),
            Row::integer($row, 'price_minor_units'),
            Row::string($row, 'currency'),
            Row::timestamp($row, 'valid_from'),
            Row::nullableTimestamp($row, 'valid_until'),
            $this->grantsOf([$id])[$id] ?? [],
            new SubscriptionTerms(
                Row::nullableInteger($row, 'term_months'),
                Row::integer($row, 'commitment_months'),
                Row::string($row, 'cancellation_policy'),
                Row::string($row, 'renewal'),
                Row::string($row, 'early_termination'),
                Row::integer($row, 'notice_days'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function toPlan(array $row): Plan
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
    /**
     * @param array<string, mixed>                                      $row
     * @param array<string, array{name: ?string, description: ?string}> $translations
     */
    public static function toFeature(array $row, array $translations = []): Feature
    {
        return new Feature(
            Row::string($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::string($row, 'kind'),
            Row::nullableString($row, 'unit'),
            Row::nullableString($row, 'description'),
            $translations,
        );
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
}
