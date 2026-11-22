<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\DemonstrationSource;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /staff/configuration/demonstrations` — tell a product where its
 * demonstrations are (2026-11-22).
 *
 * Two strings, both relative to the product's own `app_url` and both
 * carrying `{n}`. Both empty clears it. Anything that could point the
 * platform's next read somewhere else — a scheme, a host, a parent
 * segment — is refused here, where an operator can still be told why,
 * rather than dropped on the read that would have followed it.
 */
final class SetProductDemonstrationsController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);
        $source = $body->optionalNullableString('source', 500) ?? '';
        $embed = $body->optionalNullableString('embed', 500) ?? '';

        $chosen = trim($source) === '' && trim($embed) === ''
            ? null
            : (DemonstrationSource::chosen($source, $embed) ?? throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                [
                    'field' => 'source',
                    'requirement' => 'source and embed must each be a path under the product\'s own address, without a scheme or a host, and contain ' . DemonstrationSource::NUMBER,
                ],
            ));

        $this->desk->setDemonstrations($context->identity, StaffRoute::productCode($request), $chosen);

        // Not the list: writing where to look is not looking, and a save
        // that waited on somebody else's server would be a slow save.
        return new JsonResponse(ConfigurationPresenter::demonstrations($chosen, []), 200);
    }
}
