<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Theme\Domain\Palette;
use App\Theme\Domain\PaletteName;
use App\Theme\Domain\PaletteRepository;
use App\Theme\Domain\ThemeDocument;
use Doctrine\DBAL\Connection;

final class PostgresPaletteRepository implements PaletteRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        return array_map(self::hydrate(...), $this->connection->fetchAllAssociative(
            'SELECT name, document, updated_at FROM palettes ORDER BY position',
        ));
    }

    public function find(PaletteName $name): ?Palette
    {
        $row = $this->connection->fetchAssociative(
            'SELECT name, document, updated_at FROM palettes WHERE name = :name',
            ['name' => $name->value],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function save(PaletteName $name, ThemeDocument $document): Palette
    {
        // A new palette goes to the end of the list; one that exists keeps its
        // place. The position is computed in the statement, and `position` is
        // UNIQUE, so two palettes created at once cannot take the same place —
        // one of them fails and is asked again, rather than both succeeding.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO palettes (name, position, document)
                VALUES (:name, (SELECT coalesce(max(position), 0) + 1 FROM palettes), CAST(:document AS jsonb))
                ON CONFLICT (name) DO UPDATE
                   SET document = EXCLUDED.document,
                       updated_at = now()
                RETURNING name, document, updated_at
                SQL,
            ['name' => $name->value, 'document' => json_encode($document->toArray(), JSON_THROW_ON_ERROR)],
        );

        if ($row === false) {
            throw new \LogicException('An upsert returned no row.');
        }

        return self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Palette
    {
        $name = $row['name'] ?? null;
        $json = $row['document'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;

        if (!is_string($name) || !is_string($json) || !is_string($updatedAt)) {
            throw new \UnexpectedValueException('A palette row is missing a column.');
        }

        return new Palette($name, StoredDocument::read($json), new \DateTimeImmutable($updatedAt));
    }
}
