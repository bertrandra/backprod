<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminPermission;
use App\Privacy\Service\Erasure;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RequestId;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/admin/erasures — forget a person (#14, #15).
 *
 * On the admin surface rather than a self-service one, deliberately. An
 * erasure is irreversible and destroys an identity somebody else may still be
 * signed in as; it needs a human who can be named in the audit record, and
 * the response tells them exactly what was kept so the answer to the data
 * subject can be written from it.
 *
 * The response is the receipt. A caller that got only "204 No Content" would
 * have to take on trust that the accounting records survived, which is the
 * one thing about this operation nobody should have to take on trust.
 */
final class EraseUserController implements RouteHandler
{
    public function __construct(private readonly Erasure $erasure)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $staff = AdminRoute::permitted($request, AdminPermission::PRIVACY_ERASE);

        // Same shape as every other id taken from a body here; the
        // repository refuses anything that is not a uuid, and a person
        // who does not exist is a 404 rather than a silent no-op.
        $subject = JsonBody::of($request)->requiredString('user_id', 64);

        $outcome = $this->erasure->erase(
            $subject,
            $staff->identity->userId,
            $this->requestId($request),
        );

        return new JsonResponse([
            'erasure' => [
                'user_id' => $outcome->subjectUserId,
                'erased' => $outcome->erased,
                // Named, counted and justified — #15 in the response body.
                'retained' => $outcome->retained,
            ],
        ], 200);
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $requestId = $request->getAttribute(RequestId::ATTRIBUTE);

        return $requestId instanceof RequestId ? $requestId->toString() : null;
    }
}
