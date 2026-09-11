<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * The platform was asked to do something this deployment has not been set up for.
 *
 * 503 rather than 500, and the distinction is the point: nothing is broken and
 * nothing the caller sent is wrong — a capability has not been configured. An
 * operator reading their log needs to tell "somebody found a bug" apart from "I
 * have not finished deploying", and a 500 says the first about the second.
 *
 * Every capability in this platform defaults to off and each default is safe on
 * its own (see `bin/preflight.php`). This is what one of them says when reached.
 */
final class NotConfiguredException extends HttpException
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(503, $errorCode, $message);
    }
}
