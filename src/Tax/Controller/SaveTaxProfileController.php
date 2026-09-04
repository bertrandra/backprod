<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Service\Taxation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/tax/profile — and, when a VAT number is supplied, the
 * verification that decides whether reverse charge is available at all.
 *
 * The response carries `vat_number_status`, so a client can see immediately
 * that a number was submitted but not proved. Silently accepting it and
 * discovering at invoicing time that the sale is taxed after all is the
 * surprise this endpoint exists to prevent.
 */
final class SaveTaxProfileController implements RouteHandler
{
    public function __construct(private readonly Taxation $taxation)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::manageable($request);
        $body = JsonBody::of($request);

        $kind = strtoupper($body->requiredString('customer_kind', 3));

        if (!in_array($kind, [CustomerTaxProfile::B2B, CustomerTaxProfile::B2C], true)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'customer_kind', 'requirement' => 'must be B2B or B2C'],
            );
        }

        $country = $body->optionalNullableString('country_code', 2);

        if ($country !== null && preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            // An ISO 3166 country, not a VAT prefix. Greece is GR here even
            // though its numbers start EL, and a two-letter check is what
            // stops "ELX" or "FRA" reaching the database.
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'country_code', 'requirement' => 'must be a two-letter ISO 3166 country code'],
            );
        }

        $profile = $this->taxation->saveProfile(
            $context->tenantId,
            $kind,
            $country,
            $body->optionalBool('taxable_person'),
            $body->optionalNullableString('vat_number', 20),
            [],
        );

        return new JsonResponse(['profile' => TaxPresenter::profile($profile)], 200);
    }
}
