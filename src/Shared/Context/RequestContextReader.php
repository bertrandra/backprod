<?php

declare(strict_types=1);

namespace App\Shared\Context;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Reads the resolved context off a request.
 *
 * Kept separate so RequestContext itself stays free of HTTP, and so every
 * handler narrows the attribute the same way instead of each inventing its
 * own check.
 *
 * A missing context is a programming error, not a client error: it means a
 * route ran without the context middleware — i.e. it was added to the public
 * list by mistake. It therefore raises rather than returning null, so the
 * mistake surfaces as a controlled 500 instead of a handler quietly treating
 * an unauthenticated caller as anonymous.
 */
final class RequestContextReader
{
    public static function from(ServerRequestInterface $request): RequestContext
    {
        $context = $request->getAttribute(RequestContext::ATTRIBUTE);

        if (!$context instanceof RequestContext) {
            throw new RuntimeException(
                'No request context: this route ran without the context middleware.',
            );
        }

        return $context;
    }
}
