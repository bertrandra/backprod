<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Domain\BillingProfile;
use App\Billing\Service\Invoicing;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/billing/profile.
 *
 * A full replacement rather than a patch: the profile is the customer's
 * legal identity as one whole, and a partial update is how a company ends up
 * with a new address and a previous city.
 *
 * The tenant is taken from the resolved context, never from the body — a
 * client-supplied tenant id is the one thing this platform never trusts
 * (CLAUDE.md, ADR-015).
 */
final class SaveBillingProfileController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);
        $body = JsonBody::of($request);

        $profile = $this->invoicing->saveProfile(new BillingProfile(
            $context->tenantId,
            $body->requiredString('legal_name'),
            $body->optionalNullableString('vat_number', 32),
            $body->optionalNullableString('registration_number', 32),
            $body->optionalNullableString('address_line1'),
            $body->optionalNullableString('address_line2'),
            $body->optionalNullableString('postal_code', 16),
            $body->optionalNullableString('city', 128),
            self::countryCode($body),
            $body->optionalNullableString('billing_email'),
        ));

        return new JsonResponse(InvoicePresenter::profile($profile), 200);
    }

    /**
     * An ISO 3166-1 alpha-2 code, upper-cased.
     *
     * Validated here rather than left to the database's check constraint,
     * because a rejected constraint surfaces as a 500 and §11 forbids
     * leaking SQL errors — and because "france" is a mistake worth naming.
     */
    private static function countryCode(JsonBody $body): ?string
    {
        $value = $body->optionalNullableString('country_code', 8);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'country_code', 'requirement' => 'must be a two-letter ISO 3166-1 code'],
            );
        }

        return strtoupper($value);
    }
}
