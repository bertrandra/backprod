<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use App\Tax\Domain\SupplierTaxSettings;
use App\Tax\Domain\SupplyType;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/configuration/tax?product=CODE — the supplier's own fiscal
 * position.
 *
 * §25.3 is explicit that everything qualifying the *supplier* fiscally stays a
 * human decision, configured and never derived. `oss_registered` is the one that
 * matters most: it decides how a cross-border consumer sale is taxed, and
 * crossing the distance-selling threshold is a dated event that changes the
 * regime of subsequent sales and never of previous ones. Deriving it from
 * turnover would retroactively restate invoices already issued.
 *
 * Every field is required. {@see SupplierTaxSettings::fromConfiguration()}
 * defaults what it cannot read, which is right for a stored document written
 * before a field existed and wrong for a form: a screen that omitted
 * `oss_registered` would silently switch the OSS regime off.
 */
final class SetTaxSettingsController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);

        $supply = $body->requiredString('supply_type', 32);

        if (!SupplyType::isKnown($supply)) {
            // Named rather than defaulted: what is being supplied decides the
            // place of taxation, so a value nobody recognised must not quietly
            // become digital services.
            throw self::invalid('supply_type', 'must be one of ' . implode(', ', SupplyType::all()));
        }

        $tax = $this->desk->setTax(
            $context->identity,
            StaffRoute::productCode($request),
            new SupplierTaxSettings(
                self::countryCode($body->requiredString('country', 8)),
                $body->requiredBool('oss_registered'),
                $supply,
                self::currency($body->requiredString('currency', 3)),
            ),
        );

        return new JsonResponse(['tax' => ConfigurationPresenter::tax($tax)], 200);
    }

    private static function countryCode(string $value): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            throw self::invalid('country', 'must be a two-letter ISO 3166-1 country code');
        }

        return strtoupper($value);
    }

    private static function currency(string $value): string
    {
        if (preg_match('/^[A-Za-z]{3}$/', $value) !== 1) {
            throw self::invalid('currency', 'must be a three-letter ISO 4217 code');
        }

        return strtoupper($value);
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
