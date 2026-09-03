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
 * POST /api/v1/conversations/{conversationId}/read — move the watermark.
 *
 * A stale position is accepted and ignored rather than refused: two tabs at
 * different scroll depths is ordinary, and the database takes the greater of
 * the two, so reporting an older one is harmless.
 */
final class MarkReadController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::readable($request);
        $body = JsonBody::of($request);

        $participant = $this->conversations->markRead(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            $body->requiredInt('seq', 0),
        );

        return new JsonResponse(MessagingPresenter::participant($participant), 200);
    }
}
