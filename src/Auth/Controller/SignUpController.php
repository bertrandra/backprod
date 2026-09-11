<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/sign-up` — a stranger becomes a customer.
 *
 * The fourth public route, and the first that *creates* anything. Everything
 * else on this platform is reached by somebody who already has an account; a
 * storefront that sells to strangers needs a way in that does not go through
 * an administrator, and this is it.
 *
 * **`organisation` is optional and `display_name` is not required either.** A
 * consumer buying for themselves has no company and should not have to invent
 * one — the tenant takes their own name instead. A business types theirs, and
 * the invoice carries it from the first one. Nothing records which of the two
 * happened, because nothing downstream should behave differently.
 *
 * **`product` is required.** A membership is per product (§12.1), so an
 * account created without one would be an account with no way in — and
 * guessing a default would put somebody in a product they did not choose.
 *
 * It answers with a session, exactly as signing in does, and sets the same
 * refresh cookie. The purchase that follows is then an ordinary authenticated
 * checkout rather than a second anonymous flow with its own rules.
 */
final class SignUpController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::of($request);

        [$session, $account] = $this->sessions->signUp(
            $body->requiredString('email', 320),
            $body->requiredSecret('password'),
            $body->optionalNullableString('display_name', 200),
            $body->optionalNullableString('organisation', 200),
            $body->requiredString('product', 64),
            // Optional, and asked for because VAT depends on it rather than
            // because a form looks more complete with it. Absent means the
            // profile carries no country and whoever invoices decides what
            // that means.
            $body->optionalNullableString('country', 2),
        );

        // The tenant id is returned because the client has just been given a
        // token for an account with exactly one membership, and the checkout
        // that follows resolves it anyway — saying so saves a round trip and
        // makes the response describe what was actually created.
        $created = SessionPresenter::one($session) + ['tenant_id' => $account->tenantId];

        return RefreshCookie::set(
            new JsonResponse($created, 201),
            $request,
            $session->refreshToken,
            $session->refreshLifetimeSeconds,
        );
    }
}
