<?php

declare(strict_types=1);

namespace App\Shared\Context;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Who is calling, before any product or tenant has been resolved.
 *
 * A separate type from RequestContext rather than one with empty fields: a
 * handler holding this cannot accidentally read a blank tenant id and act on
 * it. If you need a tenant, you need the other one, and the type says so.
 *
 * Attached to every authenticated request, so a handler on a FULL route can
 * still ask who the caller is.
 */
final class IdentityContext
{
    public const ATTRIBUTE = 'identity_context';

    public function __construct(public readonly string $userId)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        if (!$context instanceof self) {
            // A programming error: the route ran without authentication.
            throw new RuntimeException(
                'No identity context: this route ran without the context middleware.',
            );
        }

        return $context;
    }
}
