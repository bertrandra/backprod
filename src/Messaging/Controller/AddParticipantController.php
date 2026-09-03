<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Service\Conversations;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/conversations/{conversationId}/participants.
 *
 * The person added must already be a member of this tenant and product. That
 * check is the difference between a conversation and a hole in the tenant
 * boundary.
 */
final class AddParticipantController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::writable($request);
        $body = JsonBody::of($request);

        $this->conversations->addParticipant(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            $body->requiredString('user_id', 64),
        );

        return new EmptyResponse(204);
    }
}
