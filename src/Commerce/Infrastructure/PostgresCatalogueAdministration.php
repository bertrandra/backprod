<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CatalogueAdministration;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\Plan;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

final class PostgresCatalogueAdministration implements CatalogueAdministration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createPlan(string $productId, string $code, string $name, int $rank): Plan
    {
        try {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    INSERT INTO plans (product_id, code, name, rank)
                    VALUES (:product, :code, :name, :rank)
                    RETURNING id, code, name, rank
                    SQL,
                ['product' => $productId, 'code' => $code, 'name' => $name, 'rank' => $rank],
            );
        } catch (UniqueConstraintViolationException) {
            // `plans_code_unique` is on (product_id, code), so the same code in
            // two products is fine and the same code twice in one is not.
            throw new ConflictException(
                'PLAN_CODE_TAKEN',
                'A plan of this product already uses that code.',
                ['code' => $code],
            );
        }

        if ($row === false) {
            throw new ConflictException('PLAN_NOT_CREATED', 'The plan could not be created.');
        }

        return OfferVersionLoader::toPlan($row);
    }

    public function updatePlan(string $productId, string $planId, ?string $name, ?int $rank): ?Plan
    {
        if (!Uuid::isValid($planId)) {
            return null;
        }

        // COALESCE, so null means "leave it" without building a SET list.
        // Filtered by product in the WHERE, so a plan id belonging to another
        // product updates nothing rather than being updated.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE plans
                   SET name = COALESCE(:name, name),
                       rank = COALESCE(:rank, rank),
                       updated_at = now()
                 WHERE id = :id AND product_id = :product
                RETURNING id, code, name, rank
                SQL,
            ['id' => $planId, 'product' => $productId, 'name' => $name, 'rank' => $rank],
            ['rank' => ParameterType::INTEGER],
        );

        return $row === false ? null : OfferVersionLoader::toPlan($row);
    }

    public function createFeature(
        string $productId,
        string $code,
        string $name,
        string $kind,
        ?string $unit,
    ): Feature {
        // Said here as well as by `features_unit_only_for_quotas`, because a
        // CHECK refuses with the constraint's name and somebody who put a unit
        // on a switch is owed the reason.
        if ($kind === Feature::BOOLEAN && $unit !== null) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'unit', 'requirement' => 'only a QUOTA is counted in a unit'],
            );
        }

        try {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    INSERT INTO features (product_id, code, name, kind, unit)
                    VALUES (:product, :code, :name, :kind, :unit)
                    RETURNING id, code, name, kind, unit
                    SQL,
                [
                    'product' => $productId,
                    'code' => $code,
                    'name' => $name,
                    'kind' => $kind,
                    'unit' => $unit,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException(
                'FEATURE_CODE_TAKEN',
                'A feature of this product already uses that code.',
                ['code' => $code],
            );
        }

        if ($row === false) {
            throw new ConflictException('FEATURE_NOT_CREATED', 'The feature could not be created.');
        }

        return OfferVersionLoader::toFeature($row);
    }

    /**
     * @param array<string, array{name?: ?string, description?: ?string}>|null $translations
     */
    public function renameFeature(
        string $productId,
        string $featureId,
        string $name,
        bool $setDescription = false,
        ?string $description = null,
        ?array $translations = null,
    ): ?Feature {
        if (!Uuid::isValid($featureId)) {
            return null;
        }

        // One transaction (2026-09-24): the English and its four
        // translations are one act. Written apart, a failure between them
        // leaves a feature renamed and its Spanish still saying the old
        // thing — which is exactly the state nobody would think to look for.
        return $this->connection->transactional(function () use ($productId, $featureId, $name, $setDescription, $description, $translations): ?Feature {
            // `kind` is deliberately absent from this statement. Every grant
            // written against this feature meant one kind or the other, and
            // flipping it would reinterpret rows already priced into live
            // subscriptions.
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    UPDATE features
                       SET name = :name,
                           description = CASE WHEN :setDescription THEN :description ELSE description END,
                           updated_at = now()
                     WHERE id = :id AND product_id = :product
                    RETURNING id, code, name, description, kind, unit
                    SQL,
                [
                    'id' => $featureId,
                    'product' => $productId,
                    'name' => $name,
                    'setDescription' => $setDescription,
                    'description' => $description,
                ],
                ['setDescription' => ParameterType::BOOLEAN],
            );

            if ($row === false) {
                return null;
            }

            if ($translations !== null) {
                $this->replaceTranslations($featureId, $translations);
            }

            return OfferVersionLoader::toFeature($row, $this->translationsOf($featureId));
        });
    }

    /**
     * The four other languages, replaced as a set.
     *
     * Replaced rather than merged: the console sends what the feature says
     * in every language it says anything in, so a translation removed there
     * is removed here. Merging would make deleting one impossible without a
     * route whose whole purpose was deletion.
     *
     * @param array<string, array{name?: ?string, description?: ?string}> $translations
     */
    private function replaceTranslations(string $featureId, array $translations): void
    {
        $this->connection->executeStatement(
            'DELETE FROM feature_translations WHERE feature_id = :id',
            ['id' => $featureId],
        );

        foreach ($translations as $locale => $values) {
            $name = $values['name'] ?? null;
            $description = $values['description'] ?? null;

            // A locale that says nothing is not written at all. The table
            // refuses it anyway (`feature_translations_says_something`), and
            // a row of two nulls would be a translation somebody would
            // later read as "translated, deliberately empty".
            if (($name === null || $name === '') && ($description === null || $description === '')) {
                continue;
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO feature_translations (feature_id, locale, name, description)
                    VALUES (:id, :locale, :name, :description)
                    SQL,
                [
                    'id' => $featureId,
                    'locale' => $locale,
                    'name' => $name === '' ? null : $name,
                    'description' => $description === '' ? null : $description,
                ],
            );
        }
    }

    /**
     * @return array<string, array{name: ?string, description: ?string}>
     */
    private function translationsOf(string $featureId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT locale, name, description FROM feature_translations WHERE feature_id = :id ORDER BY locale',
            ['id' => $featureId],
        );

        $translations = [];

        foreach ($rows as $row) {
            $translations[Row::string($row, 'locale')] = [
                'name' => Row::nullableString($row, 'name'),
                'description' => Row::nullableString($row, 'description'),
            ];
        }

        return $translations;
    }
}
