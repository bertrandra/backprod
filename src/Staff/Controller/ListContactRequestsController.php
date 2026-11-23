<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\ContactRequest;
use App\Product\Service\ContactDesk;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /staff/contact-requests` — what visitors wrote through product pages
 * (2026-11-23), still to answer first. Support's, like a conversation:
 * `support.read`.
 */
final class ListContactRequestsController implements RouteHandler
{
    private const LARGEST_PAGE = 100;

    public function __construct(private readonly ContactDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::SUPPORT_READ);

        $query = StaffRoute::query($request);
        $product = $query['product'] ?? null;
        $limit = self::number($query['limit'] ?? '50', 'limit', 1, self::LARGEST_PAGE);
        $offset = self::number($query['offset'] ?? '0', 'offset', 0, PHP_INT_MAX);

        $page = $this->desk->page(is_string($product) && trim($product) !== '' ? trim($product) : null, $limit, $offset);

        return new JsonResponse([
            'requests' => array_map(self::request(...), $page['requests']),
            'total' => $page['total'],
            'open' => $page['open'],
        ], 200);
    }

    /**
     * @return array<string, string|null>
     */
    public static function request(ContactRequest $request): array
    {
        return [
            'id' => $request->id,
            'product' => $request->productCode,
            'name' => $request->name,
            'email' => $request->email,
            'message' => $request->message,
            'consent' => $request->consent,
            'locale' => $request->locale,
            'received_at' => $request->receivedAt,
            'handled_at' => $request->handledAt,
        ];
    }

    private static function number(mixed $raw, string $field, int $least, int $most): int
    {
        if (!is_string($raw) || preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < $least || (int) $raw > $most) {
            throw new BadRequestException('VALIDATION_FAILED', 'The query string is not valid.', [
                'field' => $field,
                'requirement' => sprintf('a whole number from %d to %d', $least, min($most, 999_999_999)),
            ]);
        }

        return (int) $raw;
    }
}
