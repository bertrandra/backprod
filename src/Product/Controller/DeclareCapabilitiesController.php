<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ProductCapabilities;
use App\Product\Domain\ProductFeature;
use App\Product\Domain\ProductScope;
use App\Product\Service\ProductGate;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * PUT /api/v1/product/capabilities — a product says what it gates on
 * (2026-09-24, ADR-051 §4 and ADR-052). `product.capabilities.write`.
 *
 * The only product route that names no tenant: a program saying what it has
 * built is saying something about itself, so there is no customer to check
 * it against and the gate checks the scope alone.
 *
 * **Every code is checked against the platform's list and none is added to
 * it.** A program may tell the platform what it gates on and may not invent
 * a priced capability — that is `staff.features.manage`, held by a person
 * on Console → Features. Unknown codes are refused `FEATURE_CODE_UNKNOWN`
 * with what may be picked, because an integration meeting a bare "no"
 * against a list only the console can see is a guessing game.
 *
 * PUT, and replacing the set, because that is what a redeploying program
 * knows: its whole list, every time. A capability it stops shipping is one
 * it stops sending.
 */
final class DeclareCapabilitiesController implements RouteHandler
{
    /**
     * A product gates on a handful of things. The cap is a property of the
     * endpoint rather than of JSON: a list this long is a mistake, and a
     * mistake that would otherwise become a thousand-row transaction.
     */
    private const MAXIMUM = 200;

    public function __construct(
        private readonly ProductGate $gate,
        private readonly ProductCapabilities $capabilities,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProductKeyRoute::context($request);

        $this->gate->scope(
            $context->key,
            ProductScope::CAPABILITIES_WRITE,
            $request->getMethod(),
            $request->getUri()->getPath(),
        );

        $body = JsonBody::of($request);
        $declared = [];

        foreach ($body->optionalObjectList('capabilities', self::MAXIMUM) as $index => $capability) {
            $declared[] = self::capability($capability, $index);
        }

        return new JsonResponse([
            'capabilities' => array_map(
                static fn (ProductFeature $feature): array => [
                    'code' => $feature->code,
                    'name' => $feature->name,
                    'enabled' => $feature->enabled,
                ],
                $this->capabilities->declare($context->productId(), $declared),
            ),
        ], 200);
    }

    private static function capability(stdClass $capability, int $index): ProductFeature
    {
        $code = $capability->code ?? null;
        $name = $capability->name ?? null;
        $enabled = $capability->enabled ?? true;

        // Said with the position, because the caller is a program sending a
        // list and "code must be a string" names nothing it can act on.
        if (!is_string($code) || trim($code) === '' || mb_strlen($code) > 64) {
            throw self::invalid($index, 'code', 'a non-blank string of at most 64 characters');
        }

        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 200) {
            throw self::invalid($index, 'name', 'a non-blank string of at most 200 characters');
        }

        if (!is_bool($enabled)) {
            throw self::invalid($index, 'enabled', 'true or false');
        }

        return new ProductFeature(trim($code), trim($name), $enabled);
    }

    private static function invalid(int $index, string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => sprintf('capabilities[%d].%s', $index, $field), 'requirement' => $requirement],
        );
    }
}
