<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Keys from a configured JWK set.
 *
 * Enough for a pinned Supabase key and for tests. Rotation currently means a
 * configuration change — see the consequences recorded in ADR-014.
 */
final class StaticSigningKeySource implements SigningKeySource
{
    /** @var array<string, Key> */
    private readonly array $keys;

    /**
     * @param array<string, mixed> $jwks a JWK set, i.e. {"keys": [...]}
     */
    public function __construct(array $jwks)
    {
        try {
            $this->keys = JWK::parseKeySet($jwks);
        } catch (Throwable $e) {
            // The library raises several unrelated types for a malformed set;
            // all of them mean the same thing to a caller.
            throw new RuntimeException('The configured JWK set could not be parsed.', 0, $e);
        }
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The configured JWK set is not valid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function keys(): array
    {
        return $this->keys;
    }
}
