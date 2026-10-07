<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Project\Domain\DocumentLimit;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/projects/settings — set how large a project document may
 * be (2026-10-07). Platform-wide, `staff.products.manage`.
 *
 * Applies from the next save. Lowering it refuses no document already stored
 * — a stored document is only measured again when somebody saves it — so a
 * project above the new limit stays readable and is refused, with the limit
 * named, the next time it is saved.
 */
final class SetProjectSettingsController implements RouteHandler
{
    public function __construct(private readonly DocumentLimit $limit)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $mib = JsonBody::of($request)->requiredInt('max_document_mib', DocumentLimit::MINIMUM_MIB);

        if ($mib > DocumentLimit::MAXIMUM_MIB) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'max_document_mib', 'requirement' => sprintf('must be at most %d', DocumentLimit::MAXIMUM_MIB)],
            );
        }

        $this->limit->setMaxDocumentMib($mib);

        return new JsonResponse(ProjectSettings::of($this->limit), 200);
    }
}
