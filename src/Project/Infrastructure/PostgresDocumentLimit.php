<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\DocumentLimit;
use Doctrine\DBAL\Connection;

/**
 * The document limit as one platform setting (`platform_settings`, key
 * `projects`), like the sign-up policy, the menus and the storefront.
 *
 * No row, or a value that is not a whole number within bounds, reads as the
 * default: a setting that decided what may be stored on the strength of a
 * stray string would be one nobody could predict.
 */
final class PostgresDocumentLimit implements DocumentLimit
{
    public const KEY = 'projects';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function maxDocumentMib(): int
    {
        $value = $this->connection->fetchOne(
            "SELECT value->>'max_document_mib' FROM platform_settings WHERE key = :key",
            ['key' => self::KEY],
        );

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return self::DEFAULT_MIB;
        }

        $mib = (int) $value;

        return $mib >= self::MINIMUM_MIB && $mib <= self::MAXIMUM_MIB ? $mib : self::DEFAULT_MIB;
    }

    public function setMaxDocumentMib(int $mib): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode(['max_document_mib' => $mib], JSON_THROW_ON_ERROR)],
        );
    }
}
