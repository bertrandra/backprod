<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Every routed endpoint implements this.
 *
 * Controllers stay thin (CLAUDE.md "Domain boundaries"): they translate HTTP
 * to an application service call and back, and hold no business rules.
 */
interface RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
