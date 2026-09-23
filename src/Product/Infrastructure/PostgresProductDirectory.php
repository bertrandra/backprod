<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductDirectory;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

final class PostgresProductDirectory implements ProductDirectory
{
    private const COLUMNS = 'id, code, name, active, app_url, webhook_url, webhook_secret_issued_at, display_order';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        // The order somebody chose, not the order they happened to be
        // created in (2026-09-23). `code` breaks a tie, because two products
        // sharing a number is a tie and not an error.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM products ORDER BY display_order, code',
        );

        return array_map(self::toProduct(...), $rows);
    }

    public function create(string $code, string $name): Product
    {
        try {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    INSERT INTO products (code, name, active, display_order)
                    VALUES (:code, :name, true, (SELECT COALESCE(max(display_order), 0) + 10 FROM products))
                    RETURNING id, code, name, active, app_url, webhook_url, webhook_secret_issued_at, display_order
                    SQL,
                ['code' => $code, 'name' => $name],
            );
        } catch (UniqueConstraintViolationException) {
            // `products_code_unique` is the authority, and it is what makes
            // this safe to call without checking first: two administrators
            // creating the same code at once produce one product and one
            // refusal rather than two products.
            throw new ConflictException(
                'PRODUCT_CODE_TAKEN',
                'A product already uses that code.',
                ['code' => $code],
            );
        }

        if ($row === false) {
            throw new ConflictException('PRODUCT_NOT_CREATED', 'The product could not be created.');
        }

        return self::toProduct($row);
    }

    public function update(
        string $productId,
        ?string $name,
        ?bool $active,
        bool $setAppUrl = false,
        ?string $appUrl = null,
        bool $setWebhookUrl = false,
        ?string $webhookUrl = null,
        ?int $displayOrder = null,
    ): ?Product {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        // COALESCE rather than a built-up SET list: null means "leave it", and
        // expressing that in SQL keeps this one statement and one round trip.
        // RETURNING gives back the row as it now stands, so nothing reads a
        // version somebody else may have changed in between.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE products
                   SET name = COALESCE(:name, name),
                       active = COALESCE(:active, active),
                       app_url = CASE WHEN :setAppUrl THEN :appUrl ELSE app_url END,
                       webhook_url = CASE WHEN :setWebhookUrl THEN :webhookUrl ELSE webhook_url END,
                       display_order = COALESCE(:displayOrder, display_order),
                       updated_at = now()
                 WHERE id = :id
                RETURNING id, code, name, active, app_url, webhook_url, webhook_secret_issued_at, display_order
                SQL,
            [
                'id' => $productId,
                'name' => $name,
                'active' => $active,
                'setAppUrl' => $setAppUrl,
                'appUrl' => $appUrl,
                'setWebhookUrl' => $setWebhookUrl,
                'webhookUrl' => $webhookUrl,
                'displayOrder' => $displayOrder,
            ],
            ['active' => ParameterType::BOOLEAN, 'setAppUrl' => ParameterType::BOOLEAN, 'setWebhookUrl' => ParameterType::BOOLEAN],
        );

        return $row === false ? null : self::toProduct($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toProduct(array $row): Product
    {
        return new Product(
            Row::string($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::boolean($row, 'active'),
            Row::nullableString($row, 'app_url'),
            Row::nullableString($row, 'webhook_url'),
            Row::nullableTimestamp($row, 'webhook_secret_issued_at'),
            Row::integer($row, 'display_order'),
        );
    }
}
