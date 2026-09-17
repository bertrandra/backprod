<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Storefront;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/tenant?tenant=SLUG — the organisation at a URL root.
 *
 * Public, because the page that asks has no session yet: a stranger at
 * `hostname/acme/` is shown acme's window and acme's name, and a member
 * arriving there learns which of their memberships this page is about
 * before they have signed in. Without `tenant`, the bare host's own — the
 * default tenant — or 404 `NO_DEFAULT_TENANT` where none is set, which the
 * page reads as "the platform's window, as before roots".
 *
 * Name and slug only. A slug is already in the address bar, and a name is
 * what the storefront paints in the corner; nothing here says who is a
 * member or what the organisation has bought.
 */
final class PublicTenantController implements RouteHandler
{
    public function __construct(private readonly Storefront $storefront)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $slug = PublicRoute::tenantSlug($request);
        $root = $this->storefront->root($slug);
        $tenant = $root['tenant'];

        if ($tenant === null) {
            throw new NotFoundException(
                $slug === null ? 'No default tenant is set.' : 'No such organisation.',
                [],
                $slug === null ? 'NO_DEFAULT_TENANT' : 'TENANT_NOT_FOUND',
            );
        }

        return new JsonResponse([
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name, 'is_default' => $root['isDefault']],
        ], 200);
    }
}
