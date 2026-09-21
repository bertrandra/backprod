<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\IssuedProductKey;
use App\Product\Domain\ProductKey;
use App\Product\Domain\ProductKeys;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

/**
 * Product keys in PostgreSQL (ADR-051 §4).
 *
 * The bearer is `bpk_<key id>_<secret>`: twelve hex characters that name
 * the row, and thirty-two random bytes, base64url, that prove it. At rest
 * the secret is its SHA-256 — 256 random bits do not need a slow hash, and
 * a slow one would be paid on every request a product makes; the same
 * reasoning as the refresh tokens' (ADR-038). Compared in constant time.
 */
final class PostgresProductKeys implements ProductKeys
{
    private const COLUMNS = 'id, product_id, key_id, label, scopes, created_at, expires_at, revoked_at, last_used_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(string $productId, string $label, array $scopes, ?string $createdBy, ?DateTimeImmutable $expiresAt): ?IssuedProductKey
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $keyId = bin2hex(random_bytes(6));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO product_credentials (product_id, key_id, secret_hash, scopes, label, created_by, expires_at)
                SELECT p.id, :keyId, :hash, CAST(:scopes AS text[]), :label, :createdBy, :expiresAt
                  FROM products p
                 WHERE p.id = :product
                RETURNING id, product_id, key_id, label, scopes, created_at, expires_at, revoked_at, last_used_at
                SQL,
            [
                'product' => $productId,
                'keyId' => $keyId,
                'hash' => hash('sha256', $secret),
                // A text[] literal: the driver expands an array parameter into a
                // list of placeholders, which is right for IN and wrong here.
                'scopes' => '{' . implode(',', array_map(static fn (string $scope): string => '"' . addcslashes($scope, '"\\') . '"', $scopes)) . '}',
                'label' => $label,
                'createdBy' => $createdBy !== null && Uuid::isValid($createdBy) ? $createdBy : null,
                'expiresAt' => $expiresAt?->format('Y-m-d H:i:sP'),
            ],
        );

        if ($row === false) {
            return null;
        }

        return new IssuedProductKey(self::toKey($row), 'bpk_' . $keyId . '_' . $secret);
    }

    public function listFor(string $productId): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM product_credentials WHERE product_id = :product ORDER BY created_at DESC, key_id',
            ['product' => $productId],
        );

        return array_map(self::toKey(...), $rows);
    }

    public function revoke(string $productId, string $credentialId): ?ProductKey
    {
        if (!Uuid::isValid($productId) || !Uuid::isValid($credentialId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE product_credentials
                   SET revoked_at = COALESCE(revoked_at, now())
                 WHERE id = :id AND product_id = :product
                RETURNING id, product_id, key_id, label, scopes, created_at, expires_at, revoked_at, last_used_at
                SQL,
            ['id' => $credentialId, 'product' => $productId],
        );

        return $row === false ? null : self::toKey($row);
    }

    public function authenticate(string $keyId, #[SensitiveParameter] string $secret): ?ProductKey
    {
        if (!preg_match('/^[0-9a-f]{12}$/', $keyId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ', secret_hash FROM product_credentials WHERE key_id = :keyId',
            ['keyId' => $keyId],
        );

        if ($row === false || !hash_equals(Row::string($row, 'secret_hash'), hash('sha256', $secret))) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE product_credentials SET last_used_at = now() WHERE key_id = :keyId',
            ['keyId' => $keyId],
        );

        return self::toKey($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toKey(array $row): ProductKey
    {
        return new ProductKey(
            Row::string($row, 'id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'key_id'),
            Row::string($row, 'label'),
            self::scopesOf($row['scopes'] ?? null),
            Row::timestamp($row, 'created_at'),
            Row::nullableTimestamp($row, 'expires_at'),
            Row::nullableTimestamp($row, 'revoked_at'),
            Row::nullableTimestamp($row, 'last_used_at'),
        );
    }

    /**
     * PostgreSQL hands a text[] back as `{a,b}`; the driver does not unpack it.
     *
     * @return list<string>
     */
    private static function scopesOf(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (!is_string($value)) {
            return [];
        }

        $inner = trim($value, '{}');

        return $inner === '' ? [] : array_values(array_map(static fn (string $s): string => trim($s, '"'), explode(',', $inner)));
    }
}
