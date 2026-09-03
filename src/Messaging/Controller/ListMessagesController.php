<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Domain\Message;
use App\Messaging\Service\Conversations;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/conversations/{conversationId}/messages?since_seq=
 *
 * The polling read. R2 forbids a persistent process on shared hosting, so
 * there is no socket to push down — `since_seq` is what makes asking again
 * cheap, because the client says what it already has.
 */
final class ListMessagesController implements RouteHandler
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = MessagingRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->conversations->messages(
            $context->tenantId,
            $context->productId,
            $context->userId,
            MessagingRoute::id($request, 'conversationId'),
            PageRequest::bounded($query, 'since_seq', 0, 0, PHP_INT_MAX),
            PageRequest::bounded($query, 'limit', 50, 1, 200),
        );

        return new JsonResponse([
            'messages' => array_map(
                static fn (Message $message): array => MessagingPresenter::message($message),
                $page['messages'],
            ),
            'since_seq' => $page['since_seq'],
            'limit' => $page['limit'],
        ], 200);
    }
}
