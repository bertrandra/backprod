<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\CreditNotes;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/credit-notes.
 */
final class ListCreditNotesController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly CreditNotes $creditNotes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->creditNotes->list(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
            $context->documentsOf(),
        );

        return new JsonResponse([
            'credit_notes' => CreditNotePresenter::many($page['credit_notes']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
