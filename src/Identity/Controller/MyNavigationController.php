<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Navigation\Service\MenuResolver;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me/navigation — what this member's menu leaves out.
 *
 * Beside `/me/permissions`, and for the same reason: so the shell can hide
 * what it should not offer. The audience is the membership's — the
 * customer's administrator or a user — and the answer is the platform's
 * setup for that audience plus, where it asked for it, the entries with
 * nothing behind them for this tenant and product. Ids only; the shell
 * already knows what they name.
 */
final class MyNavigationController implements RouteHandler
{
    public function __construct(private readonly MenuResolver $menus)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);

        return new JsonResponse([
            'hidden' => $this->menus->forMember($context->roles, $context->tenantId, $context->productId),
        ], 200);
    }
}
