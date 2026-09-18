<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Demo\Domain\DemoPage;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/demo/page — whether the public demonstration page is on.
 */
final class ShowDemoPageController implements RouteHandler
{
    public function __construct(private readonly DemoPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DEMO_PUBLISH);

        return new JsonResponse(['published' => $this->page->isPublished()], 200);
    }
}
