<?php

declare(strict_types=1);

namespace App\Demo\Controller;

use App\Demo\Domain\DemoPage;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/demo — the demonstration page's contents, for anybody.
 *
 * Public, and deliberately so: a demonstration is something a stranger is
 * shown. That is why it is refused with a 404 (`DEMO_PAGE_OFF`) while the
 * platform administrator has not switched it on — indistinguishable from a
 * page that does not exist, so a deployment that never meant to have one
 * reveals nothing by having the route.
 */
final class PublicDemoController implements RouteHandler
{
    public function __construct(private readonly DemoPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->page->isPublished()) {
            throw new NotFoundException('There is no demonstration page.', [], 'DEMO_PAGE_OFF');
        }

        return new JsonResponse($this->page->contents(), 200, [
            // What it shows changes with every sale and every sign-up, and a
            // switch turned off must take effect at once.
            'Cache-Control' => 'no-store',
        ]);
    }
}
