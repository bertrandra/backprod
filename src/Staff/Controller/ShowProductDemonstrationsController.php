<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /staff/configuration/demonstrations` — where a product keeps its
 * demonstrations, and the ones that answer today (2026-11-22).
 *
 * The page editor offers this list where a band takes the address of a
 * live view, so an operator picks "Parcelle AE 101" rather than typing a
 * query string with a number in it. The second staff read that leaves this
 * host, after the product's manifest, and separate for the same reason.
 */
final class ShowProductDemonstrationsController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $read = $this->desk->demonstrations(StaffRoute::productCode($request));

        return new JsonResponse(ConfigurationPresenter::demonstrations($read['source'], $read['demonstrations']), 200);
    }
}
