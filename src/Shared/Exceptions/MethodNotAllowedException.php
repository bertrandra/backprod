<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(private readonly array $allowed)
    {
        parent::__construct(
            405,
            'METHOD_NOT_ALLOWED',
            'This method is not allowed for the requested resource.',
            ['allowed' => $allowed],
        );
    }

    /**
     * RFC 9110 §15.5.6: a 405 MUST generate an `Allow` header.
     *
     * The list was already in the body, where a person reading JSON finds it
     * and an HTTP client does not. Both now, from one source.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->allowed === [] ? [] : ['Allow' => implode(', ', $this->allowed)];
    }
}
