<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\MailTemplates;
use Doctrine\DBAL\Connection;

/** `platform_settings.mail_templates = {type: {subject, body}}`. */
final class PostgresMailTemplates implements MailTemplates
{
    public const KEY = 'mail_templates';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function overrides(): array
    {
        $value = $this->connection->fetchOne(
            'SELECT value FROM platform_settings WHERE key = :key',
            ['key' => self::KEY],
        );

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        $overrides = [];

        if (is_array($decoded)) {
            foreach ($decoded as $type => $template) {
                if (is_string($type) && is_array($template) && is_string($template['subject'] ?? null) && is_string($template['body'] ?? null)) {
                    $overrides[$type] = ['subject' => $template['subject'], 'body' => $template['body']];
                }
            }
        }

        return $overrides;
    }

    public function save(array $overrides): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode((object) $overrides, JSON_THROW_ON_ERROR)],
        );
    }
}
