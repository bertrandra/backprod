<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Domain\Participant;
use App\Messaging\Service\Conversations;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/conversations/{conversationId}.
 */
final class ShowConversationController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::readable($request);

        $conversation = $this->conversations->show(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
        );

        $shape = MessagingPresenter::conversation(
            $conversation,
            $this->conversations->unreadFor($conversation, $context->userId),
        );
        $shape['participants'] = array_map(
            static fn (Participant $participant): array => MessagingPresenter::participant($participant),
            $this->conversations->participantsOf($conversation),
        );

        return new JsonResponse($shape, 200);
    }
}
