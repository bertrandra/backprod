<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\SubscriptionPeople;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/subscription/people — who a subscription covers, and how many
 * it may (2026-09-19). `?seat=1` asks about the caller's own seat rather
 * than the organisation's subscription.
 */
final class ListPeopleController implements RouteHandler
{
    public function __construct(private readonly SubscriptionPeople $people)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $seat = ($request->getQueryParams()['seat'] ?? null) !== null;

        return new JsonResponse(PeoplePresenter::view(
            $this->people->of($context->tenantId, $context->productId, $context->userId, $seat),
        ), 200);
    }
}
