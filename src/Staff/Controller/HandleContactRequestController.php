<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Service\ContactDesk;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\RouteHandler;
use App\Shared\Validation\Uuid;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /staff/contact-requests/{requestId}/handled` — support has answered
 * (2026-11-23). By `support.respond`, the permission that answers a
 * conversation; a second press keeps whoever answered first.
 */
final class HandleContactRequestController implements RouteHandler
{
    public function __construct(private readonly ContactDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SUPPORT_RESPOND);
        $id = StaffRoute::id($request, 'requestId');

        if (!Uuid::isValid($id)) {
            throw new NotFoundException('No such request.', [], 'CONTACT_REQUEST_NOT_FOUND');
        }

        return new JsonResponse(
            ['request' => ListContactRequestsController::request($this->desk->handle($id, $context->userId()))],
            200,
        );
    }
}
