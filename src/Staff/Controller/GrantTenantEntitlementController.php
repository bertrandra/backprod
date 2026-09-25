<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use DateTimeImmutable;
use Exception;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement —
 * give a tenant its entitlement to a product without a sale
 * (docs/tenant-roots.md §2.8).
 *
 * PUT because the body states the whole grant: features and limits, whether
 * it opens the product to the tenant's people, an expiry or none, a plan as
 * the starting point. The same request twice is the same grant.
 *
 * **`covers_people` is what makes a trial possible** (2026-09-25). Without
 * it a grant lights the features and every workspace still refuses, because
 * coverage is a question about subscriptions (ADR-053) and staff hand out a
 * feature rather than a seat. That rule stays the default; this is how the
 * platform says it means the other thing. `staff.tenants.manage`, like assigning the product — a
 * support engineer able to hand a customer a product's features is one able
 * to give away what the platform sells. No motive header: nothing of the
 * customer's is read, and the trail carries what was given.
 */
final class GrantTenantEntitlementController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);
        $body = JsonBody::of($request);

        $limits = [];

        if ($body->has('features')) {
            foreach ($body->requiredObjectList('features', 200) as $feature) {
                [$code, $limit] = self::feature($feature);
                $limits[$code] = $limit;
            }
        }

        $until = $body->optionalNullableString('valid_until', 40);

        $granted = $this->desk->grantEntitlement(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
            $body->optionalNullableString('plan', 64),
            $limits,
            // The difference between a trial and a support exception, said
            // rather than inferred (2026-09-25). False unless chosen: a
            // grant that opens the product to everybody in the organisation
            // is the wider answer, and the wider answer takes a decision.
            $body->optionalBool('covers_people'),
            $until === null ? null : self::moment($until),
        );

        return new JsonResponse(['entitlement' => StaffPresenter::grant($granted)], 200);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function feature(stdClass $feature): array
    {
        $code = $feature->code ?? null;

        if (!is_string($code) || trim($code) === '') {
            throw self::invalid('features', 'each item names a feature by its code');
        }

        $limit = $feature->limit ?? null;

        if ($limit !== null && (!is_int($limit) || $limit < 0)) {
            throw self::invalid('features', 'a limit is a non-negative integer, or null for unlimited');
        }

        return [$code, $limit];
    }

    private static function moment(string $value): DateTimeImmutable
    {
        try {
            $moment = new DateTimeImmutable($value);
        } catch (Exception) {
            throw self::invalid('valid_until', 'must be a date and time this platform can read, such as RFC 3339');
        }

        if ($moment <= new DateTimeImmutable()) {
            throw self::invalid('valid_until', 'must be in the future; to end a grant now, withdraw it');
        }

        return $moment;
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
