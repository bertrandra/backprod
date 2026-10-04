<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Shared\Exceptions\BadRequestException;
use App\Theme\Domain\StoredTheme;
use App\Theme\Domain\ThemeDocument;
use App\Theme\Domain\ThemeName;
use App\Theme\Domain\ThemeRepository;
use Doctrine\DBAL\Connection;

final class PostgresThemeRepository implements ThemeRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT name, document, updated_at FROM themes ORDER BY name');

        return array_map(self::hydrate(...), $rows);
    }

    public function find(ThemeName $name): ?StoredTheme
    {
        $row = $this->connection->fetchAssociative(
            'SELECT name, document, updated_at FROM themes WHERE name = :name',
            ['name' => $name->value],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function save(ThemeName $name, ThemeDocument $document, string $savedBy): StoredTheme
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO themes (name, document, updated_by)
                VALUES (:name, CAST(:document AS jsonb), :by)
                ON CONFLICT (name) DO UPDATE
                   SET document = EXCLUDED.document,
                       updated_by = EXCLUDED.updated_by,
                       updated_at = now()
                RETURNING name, document, updated_at
                SQL,
            [
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

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StoredTheme
    {
        $name = $row['name'] ?? null;
        $json = $row['document'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;

        if (!is_string($name) || !is_string($json) || !is_string($updatedAt)) {
            throw new \UnexpectedValueException('A theme row is missing a column.');
        }

        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $document = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $document[(string) $key] = $value;
        }

        // `jsonb` keeps an object's keys in its own order (shortest first), so
        // a document read back raw would list `size` before `name`. Passed
        // through its own shape it reads as it was written — and a row this
        // version no longer reads as a theme is returned as stored rather than
        // made unreadable by the code that is supposed to show it.
        try {
            $document = ThemeDocument::fromArray($document)->toArray();
        } catch (BadRequestException) {
        }

        return new StoredTheme($name, $document, new \DateTimeImmutable($updatedAt));
    }
}
