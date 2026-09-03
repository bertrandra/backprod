<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Domain\Conversation;
use App\Messaging\Service\Conversations;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/conversations — the threads this caller is in.
 *
 * Scoped by participation as well as by tenant and product: belonging to a
 * tenant does not make its every conversation yours.
 */
final class ListConversationsController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->conversations->list(
            $context->tenantId,
            $context->productId,
            $context->userId,
            PageRequest::bounded($query, 'limit', 25, 1, 100),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'conversations' => array_map(
                fn (Conversation $conversation): array => MessagingPresenter::conversation(
                    $conversation,
                    $this->conversations->unreadFor($conversation, $context->userId),
                ),
                $page['conversations'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
