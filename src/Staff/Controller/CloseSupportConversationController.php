<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Messaging\Controller\MessagingPresenter;
use App\Messaging\Service\SupportDesk;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/conversations/{conversationId}/close.
 */
final class CloseSupportConversationController implements RouteHandler
{
    public function __construct(private readonly SupportDesk $support)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SUPPORT_RESPOND);

        $conversation = $this->support->close(
            $context->identity,
            StaffRoute::id($request, 'conversationId'),
        );

        return new JsonResponse(MessagingPresenter::conversation($conversation), 200);
    }
}
