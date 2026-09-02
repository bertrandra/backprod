<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Correlation id for one request.
 *
 * Architecture V2 §30 requires important events to be correlatable across
 * logs, audit records and job runs; §10.4 puts the same value in every error
 * body so a user-reported failure can be traced.
 */
final class RequestId
{
    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-Id';

    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    /**
     * Accepts a client-supplied id only when it is safe to echo back:
     * bounded length and a conservative character set, so it cannot be used
     * to inject into headers or log lines.
     */
    public static function fromClient(string $candidate): self
    {
        if (preg_match('/^[A-Za-z0-9._-]{8,128}$/', $candidate) !== 1) {
            return self::generate();
        }

        return new self($candidate);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
