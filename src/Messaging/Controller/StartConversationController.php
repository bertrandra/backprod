<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Domain\ConversationKind;
use App\Messaging\Service\Conversations;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/conversations — open a thread.
 *
 * `participants` names people to include besides the caller. Every one of
 * them is checked against this tenant's membership before the thread exists,
 * so a conversation can never become a way to show a stranger a tenant's
 * data.
 */
final class StartConversationController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);
        $body = JsonBody::of($request);

        $conversation = $this->conversations->start(
            $context->tenantId,
            $context->productId,
            $context->userId,
            $body->has('kind') ? $body->requiredString('kind', 16) : ConversationKind::INTERNAL,
            $body->requiredString('subject', Conversations::MAX_SUBJECT),
            $body->optionalStringList('participants'),
        );

        return new JsonResponse(MessagingPresenter::conversation($conversation), 201);
    }
}
