<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * The body parsed and was well-formed, but the platform will not act on it.
 *
 * Distinct from 400 on purpose. A 400 says "I could not read this"; a 422
 * says "I read it, and it is not something I can store" — an unsupported
 * schema version, an embedded asset. The two are fixed by different people:
 * one by the client's serialiser, the other by the client's content or the
 * product's configuration.
 */
final class UnprocessableEntityException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $code, string $message, array $details = [])
    {
        parent::__construct(422, $code, $message, $details);
    }
}
