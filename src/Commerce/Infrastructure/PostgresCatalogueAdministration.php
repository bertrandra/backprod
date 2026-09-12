<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CatalogueAdministration;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\Plan;
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

    public function renameFeature(string $productId, string $featureId, string $name): ?Feature
    {
        if (!Uuid::isValid($featureId)) {
            return null;
        }

        // `kind` is deliberately absent from this statement. Every grant
        // written against this feature meant one kind or the other, and
        // flipping it would reinterpret rows already priced into live
        // subscriptions.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE features
                   SET name = :name, updated_at = now()
                 WHERE id = :id AND product_id = :product
                RETURNING id, code, name, kind, unit
                SQL,
            ['id' => $featureId, 'product' => $productId, 'name' => $name],
        );

        return $row === false ? null : OfferVersionLoader::toFeature($row);
    }
}
