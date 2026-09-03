<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Service\Conversations;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/conversations/{conversationId}/messages/{messageId}.
 *
 * The body is really erased — §26 keeps invoices because the law requires it,
 * and a message carries no such obligation. What remains is a tombstone
 * holding the thread's order.
 */
final class DeleteMessageController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);

        $this->conversations->deleteMessage(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            MessagingRoute::id($request, 'messageId'),
        );

        return new EmptyResponse(204);
    }
}
