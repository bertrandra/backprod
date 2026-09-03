<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Messaging\Controller\MessagingPresenter;
use App\Messaging\Domain\Message;
use App\Messaging\Service\SupportDesk;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/conversations/{conversationId}.
 *
 * Returns the thread and its messages together, because a support person
 * opening a ticket wants to read it, and two round trips to do that would
 * write two rows into the access trail for one act.
 */
final class ShowSupportConversationController implements RouteHandler
{
    public function __construct(private readonly SupportDesk $support)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SUPPORT_READ);
        $query = StaffRoute::query($request);
        $conversationId = StaffRoute::id($request, 'conversationId');

        $page = $this->support->messages(
            $context->identity,
            $conversationId,
            PageRequest::bounded($query, 'since_seq', 0, 0, PHP_INT_MAX),
            PageRequest::bounded($query, 'limit', 50, 1, 200),
        );

        $conversation = $this->support->show($context->identity, $conversationId);
        $shape = MessagingPresenter::conversation($conversation);
        $shape['tenant_id'] = $conversation->tenantId;
        $shape['product_id'] = $conversation->productId;
        $shape['messages'] = array_map(
            static fn (Message $message): array => MessagingPresenter::message($message),
            $page['messages'],
        );

        return new JsonResponse($shape, 200);
    }
}
