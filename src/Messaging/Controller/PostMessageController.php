<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Service\Conversations;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/conversations/{conversationId}/messages.
 */
final class PostMessageController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);
        $body = JsonBody::of($request);

        $message = $this->conversations->post(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            $body->requiredString('body', Conversations::MAX_BODY),
        );

        return new JsonResponse(MessagingPresenter::message($message), 201);
    }
}
