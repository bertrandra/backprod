<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Theme\Domain\StoredTheme;
use App\Theme\Domain\TenantThemeRepository;
use App\Theme\Domain\ThemeDocument;
use App\Theme\Domain\ThemeName;
use Doctrine\DBAL\Connection;

final class PostgresTenantThemeRepository implements TenantThemeRepository
{
    private const COLUMNS = 'name, document, updated_at, active';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(string $tenantId, string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM tenant_themes WHERE tenant_id = :tenant AND product_id = :product ORDER BY name',
            ['tenant' => $tenantId, 'product' => $productId],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function find(string $tenantId, string $productId, ThemeName $name): ?StoredTheme
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM tenant_themes WHERE tenant_id = :tenant AND product_id = :product AND name = :name',
            ['tenant' => $tenantId, 'product' => $productId, 'name' => $name->value],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function active(string $tenantId, string $productId): ?StoredTheme
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM tenant_themes WHERE tenant_id = :tenant AND product_id = :product AND active',
            ['tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function save(string $tenantId, string $productId, ThemeName $name, ThemeDocument $document, string $savedBy): StoredTheme
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO tenant_themes (tenant_id, product_id, name, document, updated_by)
                VALUES (:tenant, :product, :name, CAST(:document AS jsonb), :by)
                ON CONFLICT (tenant_id, product_id, name) DO UPDATE
                   SET document = EXCLUDED.document,
                       updated_by = EXCLUDED.updated_by,
                       updated_at = now()
                RETURNING name, document, updated_at, active
                SQL,
            [
                'tenant' => $tenantId,
                'product' => $productId,
                'name' => $name->value,
                'document' => json_encode($document->toArray(), JSON_THROW_ON_ERROR),
                'by' => $savedBy,
            ],
        );

        if ($row === false) {
            throw new \LogicException('An upsert returned no row.');
        }

        return self::hydrate($row);
    }

    public function activate(string $tenantId, string $productId, ?ThemeName $name): bool
    {
        // One transaction, two statements, in this order. A unique *index* is
        // checked row by row as an UPDATE goes, so switching the new one on and
        // the old one off in one statement would fail or pass on whichever row
        // Postgres happened to visit first. Off, then on, is never two at once.
        return $this->connection->transactional(function (Connection $connection) use ($tenantId, $productId, $name): bool {
            if ($name !== null && $connection->fetchOne(
                'SELECT 1 FROM tenant_themes WHERE tenant_id = :tenant AND product_id = :product AND name = :name FOR UPDATE',
                ['tenant' => $tenantId, 'product' => $productId, 'name' => $name->value],
            ) === false) {
                return false;
            }

            $connection->executeStatement(
                'UPDATE tenant_themes SET active = false WHERE tenant_id = :tenant AND product_id = :product AND active',
                ['tenant' => $tenantId, 'product' => $productId],
            );

            if ($name !== null) {
                $connection->executeStatement(
                    'UPDATE tenant_themes SET active = true WHERE tenant_id = :tenant AND product_id = :product AND name = :name',
                    ['tenant' => $tenantId, 'product' => $productId, 'name' => $name->value],
                );
            }

            return true;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StoredTheme
    {
        $name = $row['name'] ?? null;
        $json = $row['document'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;
        $active = $row['active'] ?? null;

        if (!is_string($name) || !is_string($json) || !is_string($updatedAt) || !is_bool($active)) {
            throw new \UnexpectedValueException('A tenant theme row is missing a column.');
        }

        return new StoredTheme($name, StoredDocument::read($json), new \DateTimeImmutable($updatedAt), $active);
    }
}
