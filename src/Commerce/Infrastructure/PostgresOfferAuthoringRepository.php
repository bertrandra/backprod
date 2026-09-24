<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\OfferAuthoringRepository;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferDraft;
use App\Shared\Database\Row;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Throwable;

/**
 * Writing the catalogue.
 *
 * Three things happen here that the database is the only honest place for.
 *
 * **A version number is `max + 1` computed inside the insert**, never read
 * and then written back. Two authors adding a version at the same moment
 * would otherwise both read 3 and both write 4, and the unique index would
 * turn one of them into a 500 rather than into version 5.
 *
 * **A plan or feature belongs to the product or the write does not happen.**
 * The check is a join in the statement that does the writing, not a SELECT
 * before it: between a separate check and the insert, the row can move.
 *
 * **The refusals come from the constraints, not from a re-implementation of
 * them.** Publishing across a window another version already occupies is
 * refused by the exclusion constraint added with this feature; a version
 * that is not a DRAFT is refused by the guarded UPDATE affecting no rows.
 * Both are translated here into the §10.4 envelope, and neither is checked
 * twice.
 */
final class PostgresOfferAuthoringRepository implements OfferAuthoringRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OfferVersionLoader $versions,
    ) {
    }

    public function createOffer(
        string $productId,
        string $code,
        string $name,
        string $planId,
        OfferDraft $draft,
    ): OfferCandidate {
        $offerId = $this->connection->transactional(
            function () use ($productId, $code, $name, $planId, $draft): string {
                // The plan is filtered by product in the INSERT itself, so a
                // plan id belonging to another product inserts nothing rather
                // than creating an offer that points across the boundary.
                try {
                    $offerId = $this->connection->fetchOne(
                        <<<'SQL'
                            INSERT INTO offers (product_id, plan_id, code, name)
                            SELECT :productId, pl.id, :code, :name
                              FROM plans pl
                             WHERE pl.id = :planId AND pl.product_id = :productId
                            RETURNING id
                            SQL,
                        ['productId' => $productId, 'planId' => $planId, 'code' => $code, 'name' => $name],
                    );
                } catch (UniqueConstraintViolationException) {
                    throw new ConflictException(
                        'OFFER_CODE_TAKEN',
                        'Another offer in this product already uses that code.',
                        ['code' => $code],
                    );
                }

                if (!is_string($offerId)) {
                    throw new NotFoundException('Plan not found.', [], 'PLAN_NOT_FOUND');
                }

                $this->insertVersion($productId, $offerId, $draft);

                return $offerId;
            },
        );

        return $this->reload($productId, $offerId);
    }

    /**
     * @param array<string, array{name: ?string, description: ?string}>|null $translations
     */
    public function renameOffer(
        string $productId,
        string $offerId,
        string $name,
        ?array $translations = null,
    ): OfferCandidate {
        // One transaction: the English and its four translations are one
        // act, and written apart a failure between them leaves an offer
        // renamed here and saying the old thing in Spanish.
        $this->connection->transactional(function () use ($productId, $offerId, $name, $translations): void {
            $changed = (int) $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE offers SET name = :name, updated_at = now()
                     WHERE id = :offerId AND product_id = :productId
                    SQL,
                ['offerId' => $offerId, 'productId' => $productId, 'name' => $name],
            );

            if ($changed === 0) {
                throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
            }

            if ($translations === null) {
                return;
            }

            // Replaced as a set, like a feature's: a language the console
            // left out is one it removed.
            $this->connection->executeStatement(
                'DELETE FROM offer_translations WHERE offer_id = :id',
                ['id' => $offerId],
            );

            foreach ($translations as $locale => $values) {
                $translated = $values['name'] ?? null;

                if ($translated === null || trim($translated) === '') {
                    continue;
                }

                $this->connection->executeStatement(
                    'INSERT INTO offer_translations (offer_id, locale, name) VALUES (:id, :locale, :name)',
                    ['id' => $offerId, 'locale' => $locale, 'name' => trim($translated)],
                );
            }
        });

        return $this->reload($productId, $offerId);
    }

    public function addVersion(string $productId, string $offerId, OfferDraft $draft): OfferCandidate
    {
        $this->connection->transactional(function () use ($productId, $offerId, $draft): void {
            $this->insertVersion($productId, $offerId, $draft);
        });

        return $this->reload($productId, $offerId);
    }

    public function publishVersion(string $productId, string $offerId, int $version): OfferCandidate
    {
        // The join on offers is what scopes this to the product: without it a
        // caller who knew an offer id could publish into somebody else's
        // catalogue.
        try {
            $published = (int) $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE offer_versions v
                       SET status = 'ACTIVE', updated_at = now()
                      FROM offers o
                     WHERE o.id = v.offer_id
                       AND o.id = :offerId
                       AND o.product_id = :productId
                       AND v.version = :version
                       AND v.status = 'DRAFT'
                    SQL,
                ['offerId' => $offerId, 'productId' => $productId, 'version' => $version],
            );
        } catch (Throwable $e) {
            // 23P01 is exclusion_violation: another version of this offer is
            // already on sale across an overlapping window. The database
            // decided that, and re-deriving it here would be a second opinion
            // that could disagree.
            if (!str_contains($e->getMessage(), 'offer_versions_one_on_sale_at_a_time')) {
                throw $e;
            }

            throw new ConflictException(
                'OFFER_ALREADY_ON_SALE',
                'Another version of this offer is already on sale over that period.',
                ['version' => $version],
            );
        }

        if ($published === 0) {
            throw new ConflictException(
                'VERSION_NOT_PUBLISHABLE',
                'That version does not exist as a draft. A published version cannot be published again.',
                ['version' => $version],
            );
        }

        return $this->reload($productId, $offerId);
    }

    public function versionsOf(string $productId, string $offerId): ?OfferCandidate
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT o.id, o.code, o.name,
                       pl.id AS plan_id, pl.code AS plan_code,
                       pl.name AS plan_name, pl.rank AS plan_rank
                  FROM offers o
                  JOIN plans pl ON pl.id = o.plan_id
                 WHERE o.id = :offerId AND o.product_id = :productId
                SQL,
            ['offerId' => $offerId, 'productId' => $productId],
        );

        if ($row === false) {
            return null;
        }

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
            // Drafts included: this is the authoring view, and the thing an
            // author most needs to see is what they have not published.
            $this->versions->versionsOf([$id], false)[$id] ?? [],
        );
    }

    /**
     * The draft, as the next DRAFT version of an offer this product owns.
     */
    private function insertVersion(string $productId, string $offerId, OfferDraft $draft): void
    {
        // `max(version) + 1` is computed by the same statement that writes the
        // row, so two concurrent authors serialise on the unique index rather
        // than racing between a read and a write.
        $versionId = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO offer_versions (
                    offer_id, version, status, billing_period, price_minor_units, currency,
                    valid_from, valid_until, term_months, commitment_months,
                    cancellation_policy, renewal, early_termination, notice_days
                )
                SELECT o.id,
                       coalesce((SELECT max(v.version) FROM offer_versions v WHERE v.offer_id = o.id), 0) + 1,
                       'DRAFT', :billingPeriod, :price, :currency,
                       :validFrom, :validUntil, :termMonths, :commitmentMonths,
                       :cancellationPolicy, :renewal, :earlyTermination, :noticeDays
                  FROM offers o
                 WHERE o.id = :offerId AND o.product_id = :productId
                RETURNING id
                SQL,
            [
                'offerId' => $offerId,
                'productId' => $productId,
                'billingPeriod' => $draft->billingPeriod,
                'price' => $draft->priceMinorUnits,
                'currency' => $draft->currency,
                'validFrom' => $draft->validFrom->format('c'),
                'validUntil' => $draft->validUntil?->format('c'),
                'termMonths' => $draft->terms->termMonths,
                'commitmentMonths' => $draft->terms->commitmentMonths,
                'cancellationPolicy' => $draft->terms->cancellationPolicy,
                'renewal' => $draft->terms->renewal,
                'earlyTermination' => $draft->terms->earlyTermination,
                'noticeDays' => $draft->terms->noticeDays,
            ],
        );

        if (!is_string($versionId)) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        foreach ($draft->grants as $featureId => $limit) {
            // No product filter since 2026-09-24: there is one list of
            // features and every product's offers grant out of it
            // (`docs/translatable-fields-spec.md` §4). `active` replaces the
            // filter that used to be there — a retired feature is precisely
            // one no new offer may grant, and this is where a new offer is
            // written.
            $granted = (int) $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    SELECT :versionId, f.id, :limit
                      FROM features f
                     WHERE f.id = :featureId AND f.active
                    SQL,
                [
                    'versionId' => $versionId,
                    'featureId' => $featureId,
                    'limit' => $limit,
                ],
            );

            if ($granted === 0) {
                // Inside the transaction, so the version goes with it: an
                // offer version granting only the features that happened to
                // resolve would be worse than no version at all.
                throw new NotFoundException(
                    'Feature not found.',
                    ['feature_id' => $featureId],
                    'FEATURE_NOT_FOUND',
                );
            }
        }
    }

    private function reload(string $productId, string $offerId): OfferCandidate
    {
        $candidate = $this->versionsOf($productId, $offerId);

        if ($candidate === null) {
            // Unreachable: the row was written in the transaction that just
            // committed. Failing loudly beats returning a fiction.
            throw new RuntimeException('The offer disappeared between writing it and reading it back.');
        }

        return $candidate;
    }
}
