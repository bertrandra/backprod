<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Theme\Domain\ThemeTemplate;
use App\Theme\Domain\ThemeTemplates;
use Doctrine\DBAL\Connection;

final class PostgresThemeTemplates implements ThemeTemplates
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        $templates = [];

        foreach ($this->connection->fetchAllAssociative('SELECT name, document FROM theme_templates ORDER BY position') as $row) {
            $name = $row['name'] ?? null;
            $json = $row['document'] ?? null;

            if (!is_string($name) || !is_string($json)) {
                throw new \UnexpectedValueException('A theme template row is missing a column.');
            }

            $templates[] = new ThemeTemplate($name, StoredDocument::read($json));
        }

        return $templates;
    }
}
