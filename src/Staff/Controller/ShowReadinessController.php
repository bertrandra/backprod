<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Payment\Service\PaymentProviders;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\SetupStep;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ReadinessDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/readiness?product=CODE — what this product still needs
 * before a stranger can buy from it.
 *
 * The chain in dependency order, each link with what was counted or found
 * missing. Following it top to bottom never meets a refusal, which is the whole
 * difference between this and the maze it replaces.
 *
 * **`PaymentProviders` is read here and not in the desk.** Whether a deployment
 * has a payment provider is a fact about the *server*, held by an application
 * service the desk may not depend on (§37.6 keeps Application from reaching
 * Application). The controller has both and hands one to the other, which is
 * the shape the layering asks for rather than a workaround of it.
 */
final class ShowReadinessController implements RouteHandler
{
    public function __construct(
        private readonly ReadinessDesk $desk,
        private readonly PaymentProviders $payments,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $readiness = $this->desk->of(
            StaffRoute::productCode($request),
            $this->payments->isConfigured()
                ? [
                    'name' => $this->payments->default()->name(),
                    'sandbox' => $this->payments->default()->isSandbox(),
                ]
                : null,
        );

        $steps = $readiness['steps'];
        $blocking = array_values(array_filter($steps, static fn (SetupStep $s): bool => $s->blocks()));

        return new JsonResponse([
            'product' => [
                'id' => $readiness['product']->id,
                'code' => $readiness['product']->code,
                'name' => $readiness['product']->name,
            ],
            'steps' => array_map(
                static fn (SetupStep $step): array => [
                    'key' => $step->key,
                    'done' => $step->done,
                    'blocking' => $step->blocking,
                    'detail' => (object) $step->detail,
                ],
                $steps,
            ),
            // The two summaries a screen actually renders, computed here so a
            // console and a script cannot disagree about what "ready" means.
            'sellable' => $blocking === [],
            // The first thing to do. Null when nothing blocks, which is what
            // lets a screen say "done" rather than pointing at nowhere.
            'next' => $blocking === [] ? null : $blocking[0]->key,
        ], 200);
    }
}
