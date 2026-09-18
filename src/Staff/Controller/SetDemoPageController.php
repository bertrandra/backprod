<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Demo\Domain\DemoPage;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/demo/page — switch the public demonstration page on or
 * off. `staff.demo.publish`, PLATFORM_ADMIN alone: what the page shows is a
 * membership's answer everywhere else, and publishing it is a decision the
 * person running the platform makes, not a support engineer.
 */
final class SetDemoPageController implements RouteHandler
{
    public function __construct(private readonly DemoPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DEMO_PUBLISH);

        $published = JsonBody::of($request)->requiredBool('published');
        $this->page->publish($published);

        return new JsonResponse(['published' => $this->page->isPublished()], 200);
    }
}
