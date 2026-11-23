<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ContactRequest;
use App\Product\Domain\ContactRequests;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PostgresContactRequests implements ContactRequests
{
    private const COLUMNS = <<<'SQL'
        c.id, c.product_id, p.code AS product_code, c.name, c.email, c.message, c.consent, c.locale,
        to_char(c.received_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS received_at,
        to_char(c.handled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS handled_at,
        c.handled_by
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(
        string $productId,
        string $name,
        string $email,
        string $message,
        string $consent,
        string $locale,
    ): ContactRequest {
        $row = $this->connection->fetchAssociative(
            'WITH c AS ('
            . 'INSERT INTO contact_requests (product_id, name, email, message, consent, locale)'
            . ' VALUES (:product, :name, :email, :message, :consent, :locale) RETURNING *'
            . ') SELECT ' . self::COLUMNS . ' FROM c JOIN products p ON p.id = c.product_id',
            [
                'product' => $productId,
                'name' => $name,
                'email' => $email,
                'message' => $message,
                'consent' => $consent,
                'locale' => $locale,
            ],
        );

        if ($row === false) {
            throw new \RuntimeException('The contact request was not kept.');
        }

        return self::fromRow($row);
    }

    public function page(?string $productId, int $limit, int $offset): array
    {
        $where = $productId === null ? '' : ' WHERE c.product_id = :product';
        $parameters = $productId === null ? [] : ['product' => $productId];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM contact_requests c JOIN products p ON p.id = c.product_id'
            . $where
            // Those still to answer first, the newest of each first.
            . ' ORDER BY c.handled_at IS NOT NULL, c.received_at DESC, c.id'
            . ' LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $counts = $this->connection->fetchAssociative(
            'SELECT count(*) AS total, count(*) FILTER (WHERE c.handled_at IS NULL) AS open'
            . ' FROM contact_requests c' . $where,
            $parameters,
        );

        return [
            'requests' => array_map(self::fromRow(...), $rows),
            'total' => $counts === false ? 0 : Row::integer($counts, 'total'),
            'open' => $counts === false ? 0 : Row::integer($counts, 'open'),
        ];
    }

    public function handle(string $id, string $staffUserId): ?ContactRequest
    {
        $row = $this->connection->fetchAssociative(
            'WITH c AS ('
            // The first answer stands: a second press records nothing new.
            . 'UPDATE contact_requests SET handled_at = COALESCE(handled_at, now()),'
            . ' handled_by = CASE WHEN handled_at IS NULL THEN CAST(:staff AS uuid) ELSE handled_by END'
            . ' WHERE id = :id RETURNING *'
            . ') SELECT ' . self::COLUMNS . ' FROM c JOIN products p ON p.id = c.product_id',
            ['id' => $id, 'staff' => $staffUserId],
        );

        return $row === false ? null : self::fromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ContactRequest
    {
        return new ContactRequest(
            Row::string($row, 'id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'product_code'),
            Row::string($row, 'name'),
            Row::string($row, 'email'),
            Row::string($row, 'message'),
            Row::string($row, 'consent'),
            Row::string($row, 'locale'),
            Row::string($row, 'received_at'),
            Row::nullableString($row, 'handled_at'),
            Row::nullableString($row, 'handled_by'),
        );
    }
}
