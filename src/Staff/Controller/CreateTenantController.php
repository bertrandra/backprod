<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/tenants — make an organisation (2026-09-17).
 *
 * Name, slug, the products it holds by code, and optionally the user id of
 * its first administrator. The slug is the organisation's address,
 * `hostname/{slug}/`, so it is a lowercase word of letters, digits and
 * hyphens, and never one of the application's own first segments — an
 * organisation called `console` would shadow the console.
 */
final class CreateTenantController implements RouteHandler
{
    /** First path segments the application owns; a slug may not be one. */
    public const RESERVED = [
        'api', 'assets', 'billing-profile', 'branding', 'catalogue', 'checkout', 'console',
        'conversations', 'credit-notes', 'invoices', 'jobs', 'members', 'notification-settings',
        'notifications', 'offers', 'orders', 'organisation', 'payments', 'profile', 'projects',
        'quotes', 'sign-in', 'sign-up', 'subscription', 'tax',
    ];

    public function __construct(
        private readonly StaffDesk $desk,
        private readonly ProductRepository $products,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);
        $body = JsonBody::of($request);

        $productIds = [];

        foreach ($body->optionalStringList('products') as $code) {
            $product = $this->products->findByCode($code);

            if ($product === null) {
                throw self::invalid('products', 'must name products that exist: ' . $code . ' does not');
            }

            $productIds[] = $product->id;
        }

        $account = $this->desk->createTenant(
            $context->identity,
            $body->requiredString('name', 120),
            self::slug($body->requiredString('slug', 63)),
            $productIds,
            $body->has('admin_user_id') ? $body->optionalNullableString('admin_user_id', 64) : null,
        );

        return new JsonResponse(['tenant' => StaffPresenter::tenant($account)], 201);
    }

    public static function slug(string $value): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $value) !== 1) {
            throw self::invalid('slug', 'must be a lowercase word of letters, digits and hyphens');
        }

        if (in_array($value, self::RESERVED, true)) {
            throw self::invalid('slug', 'is a path the application uses and cannot be an organisation\'s');
        }

        return $value;
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }
}
