<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Skin\Service\TenantSkin;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/tenant/skin — set or clear the colours.
 *
 * A PATCH, so a field the body does not mention keeps its value. That leaves
 * two different instructions to tell apart, and they are told apart by
 * presence rather than by value: omitting `accent_color` leaves it alone,
 * and sending it as `null` clears it. A caller who wants to drop one colour
 * without knowing the other can say so.
 *
 * The logo is not settable here — it is bytes, and it has its own endpoint.
 */
final class UpdateSkinController implements RouteHandler
{
    public function __construct(private readonly TenantSkin $skins)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SkinRoute::manageable($request);
        $body = JsonBody::of($request);

        $changes = [];

        foreach (['primary_color', 'accent_color'] as $field) {
            if ($body->has($field)) {
                // `optionalNullableString` already refuses a number or an
                // object and reads null (or blank) as "clear it"; `has`
                // above is what separates that from "not mentioned".
                $changes[$field] = SkinRoute::colour($body->optionalNullableString($field, 7), $field);
            }
        }

        $skin = $this->skins->change($context->tenantId, $context->productId, $changes, $context->userId);

        return new JsonResponse(['skin' => SkinPresenter::skin($skin)], 200);
    }
}
