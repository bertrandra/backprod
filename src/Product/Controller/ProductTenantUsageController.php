<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ProductScope;
use App\Product\Service\ProductGate;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use DateTimeImmutable;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/product/tenants/{tenantId}/usage — a metered fact (ADR-051
 * §4, §38.3), reported by the product before it does the work, so the
 * platform can refuse it against the quota. `quantity` is a signed delta and
 * the sum of the deltas is the level; `idempotency_key` is the product's
 * own name for the fact, so a retried report counts once.
 * `product.usage.write`.
 */
final class ProductTenantUsageController implements RouteHandler
{
    public function __construct(private readonly ProductGate $gate)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProductKeyRoute::context($request);
        $tenantId = ProductKeyRoute::tenant($request, $this->gate, ProductScope::USAGE_WRITE);

        $body = JsonBody::of($request);
        $feature = $body->requiredString('feature', 100);
        $quantity = $body->requiredInt('quantity', -1_000_000);
        $key = $body->requiredString('idempotency_key', 200);

        if ($quantity === 0 || $quantity > 1_000_000) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'quantity', 'requirement' => 'a non-zero delta between -1000000 and 1000000']);
        }

        $answer = $this->gate->reportUsage(
            $context->key,
            $tenantId,
            $feature,
            $quantity,
            $key,
            self::day($body, 'period_start'),
            self::day($body, 'period_end'),
        );

        return new JsonResponse($answer, $answer['recorded'] ? 201 : 200);
    }

    private static function day(JsonBody $body, string $field): ?DateTimeImmutable
    {
        $value = $body->has($field) ? $body->optionalNullableString($field, 10) : null;

        if ($value === null) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => $field, 'requirement' => 'a date, YYYY-MM-DD']);
        }

        return $day;
    }
}
