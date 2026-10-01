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
 * GET /api/v1/staff/configuration/product-manifest?product=CODE — what the
 * product itself says it accepts, asked of the product.
 *
 * **A read that leaves this host.** Every other staff read answers from the
 * platform's own database; this one fetches a file from the address staff
 * configured as the product's `app_url`. It is therefore slow in a way the
 * others are not — ten seconds at worst — and the screen asks for it on its
 * own, never as part of the configuration read beside it, so a product whose
 * host is down does not make the billing settings unopenable.
 *
 * It writes nothing. Applying what the product declares is a second, deliberate
 * call to `PUT …/project-schema-versions`, which is what keeps the console the
 * authority over a list a remote file only proposes.
 *
 * `staff.products.manage` rather than a read permission, because the answer
 * exists to be acted on by the same person and discloses the address and
 * release of a product the platform runs.
 */
final class ShowProductManifestController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $read = $this->desk->manifest(StaffRoute::productCode($request));

        return new JsonResponse(
            ConfigurationPresenter::manifest(
                $read['product'],
                $read['answer'],
                $read['stored'],
                $read['missing'],
            ),
            200,
        );
    }
}
