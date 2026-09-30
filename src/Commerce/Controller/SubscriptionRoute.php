<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The subscription an organisation route names.
 *
 * An empty string rather than a refusal when the attribute is missing: the
 * service resolves it against the caller's own tenant and product and answers
 * not-found, which is the one answer "no such subscription", "not this
 * organisation's" and "malformed" must share.
 */
final class SubscriptionRoute
{
    public static function subscriptionId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('subscriptionId');

        return is_string($value) ? $value : '';
    }
}
