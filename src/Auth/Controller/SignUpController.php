<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Validation\Locale;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/sign-up` — a person asks to join the organisation at
 * the URL root (2026-09-17).
 *
 * The fourth public route, and the first that *creates* anything: a
 * person. It no longer creates an organisation — those are made by the
 * platform (`POST /staff/tenants`) — and it never makes an administrator.
 * The person becomes a USER of the organisation named by `tenant`, the
 * slug of the root they signed up at, on every product it holds; whether
 * the membership is live at once or waits for an administrator is the
 * organisation's join policy to decide, and a policy that admits nobody by
 * themselves refuses the request before any account exists.
 *
 * It answers with a session, exactly as signing in does, and sets the same
 * refresh cookie — and says whether the membership is ACTIVE or PENDING, so
 * the page can tell the person they are in, or waiting.
 */
final class SignUpController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::of($request);

        // The language they were reading in (ADR-050): one the platform
        // speaks, or nothing — never a free string on the account.
        $locale = $body->optionalNullableString('locale', 8);

        if ($locale !== null && !Locale::isKnown($locale)) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'locale', 'requirement' => 'one of ' . implode(', ', Locale::ALL)]);
        }

        [$session, $account] = $this->sessions->signUp(
            $body->requiredString('email', 320),
            $body->requiredSecret('password'),
            $body->optionalNullableString('display_name', 200),
            // The organisation whose root the page is on (2026-09-17): the
            // person asks to join it, as a USER, and its join policy answers.
            $body->requiredString('tenant', 63),
            // The product the page was showing, if any: their default from
            // then on, provided the organisation holds it.
            $body->optionalNullableString('product', 64),
            $locale,
        );

        // What was made: a session, and a membership that is either live or
        // waiting. A waiting one is told so plainly, because the person can
        // sign in and yet reach nothing until an administrator has looked.
        $created = SessionPresenter::one($session) + [
            'tenant_id' => $account->tenantId,
            'tenant' => $account->tenantSlug,
            'membership' => $account->membershipStatus,
        ];

        return RefreshCookie::set(
            new JsonResponse($created, 201),
            $request,
            $session->refreshToken,
            $session->refreshLifetimeSeconds,
        );
    }
}
