<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Service\Conversations;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/conversations/{conversationId}/close.
 */
final class CloseConversationController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);

        $conversation = $this->conversations->close(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
        );

        return new JsonResponse(MessagingPresenter::conversation($conversation), 200);
    }
}
