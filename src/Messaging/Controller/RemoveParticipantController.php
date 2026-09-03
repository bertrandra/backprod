<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Service\Conversations;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/conversations/{conversationId}/participants/{userId}.
 *
 * Marks them as having left. The row survives, because their messages keep
 * their author and the thread keeps its history.
 */
final class RemoveParticipantController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);

        $this->conversations->removeParticipant(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            MessagingRoute::id($request, 'userId'),
        );

        return new EmptyResponse(204);
    }
}
