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
     * `description` and `active` are read only when the statement asked for
     * them: a grant's join wants the feature's identity, not the platform's
     * view of it, and a column nobody selected is absent rather than false.
     *
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
            !array_key_exists('active', $row) || Row::boolean($row, 'active'),
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

        // What each of those features is called in the other four languages
        // (2026-09-24). Loaded here because a grant is read on the pages a
        // *customer* sees — the shop window, the catalogue, a subscription —
        // and until today it was not: a French storefront listed `Lecture,
        // mensuel` over `Exports`, `Projects` and `Users`, the offer's own
        // name translated and everything it grants in English.
        //
        // A second query rather than a join, because a feature has up to four
        // translations and joining them would multiply the grant rows — then
        // one offer would appear to grant the same feature four times.
        $translations = $this->translationsOf(array_values(array_unique(
            array_map(static fn (array $row): string => Row::string($row, 'id'), $rows),
        )));

        $grants = [];

        foreach ($rows as $row) {
            $featureId = Row::string($row, 'id');

            $grants[Row::string($row, 'offer_version_id')][] = new OfferGrant(
                self::toFeature($row, $translations[$featureId] ?? []),
                Row::nullableInteger($row, 'limit_value'),
            );
        }

        return $grants;
    }

    /**
     * What an operator wrote about these features in the four other
     * languages, by feature and then by locale.
     *
     * On the loader rather than on one of its callers because both of them
     * want it: the features list and everything an offer grants are the same
     * rows read for two reasons.
     *
     * @param list<string> $featureIds
     *
     * @return array<string, array<string, array{name: ?string, description: ?string}>>
     */
    public function translationsOf(array $featureIds): array
    {
        if ($featureIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_id, locale, name, description
                  FROM feature_translations
                 WHERE feature_id = ANY(CAST(:ids AS uuid[]))
                 ORDER BY feature_id, locale
                SQL,
            ['ids' => '{' . implode(',', $featureIds) . '}'],
        );

        $byFeature = [];

        foreach ($rows as $row) {
            $byFeature[Row::string($row, 'feature_id')][Row::string($row, 'locale')] = [
                'name' => Row::nullableString($row, 'name'),
                'description' => Row::nullableString($row, 'description'),
            ];
        }

        return $byFeature;
    }
}
