<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Messaging\Controller\MessagingPresenter;
use App\Messaging\Service\Conversations;
use App\Messaging\Service\SupportDesk;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/conversations/{conversationId}/messages — reply to a
 * customer.
 *
 * This is the endpoint the whole milestone existed to make possible: a sender
 * who is not a member of the tenant being written to.
 */
final class PostSupportMessageController implements RouteHandler
{
    public function __construct(private readonly SupportDesk $support)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SUPPORT_RESPOND);
        $body = JsonBody::of($request);

        $message = $this->support->post(
            $context->identity,
            StaffRoute::id($request, 'conversationId'),
            $body->requiredString('body', Conversations::MAX_BODY),
        );

        return new JsonResponse(MessagingPresenter::message($message), 201);
    }
}
