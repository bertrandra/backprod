<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Billing\Domain\SupplierDetails;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/configuration/billing-identity?product=CODE — who this
 * product's invoices say is issuing them.
 *
 * **PUT and not PATCH**, unlike every other write in this console. The mandatory
 * mentions of a French invoice are one document (§25): a supplier that stops
 * being liable for VAT has to be able to *remove* its VAT number, and under
 * PATCH-with-COALESCE an omitted field means "leave it", so removing anything
 * would be impossible. Sending the whole identity makes clearing a field the
 * same act as changing one.
 *
 * Length is checked here and completeness is not: which mentions an invoice
 * cannot do without is {@see SupplierDetails}'s rule, shared with the invoice
 * path that refuses to issue without them, and restating it here would be a
 * second answer to the same question.
 */
final class SetBillingIdentityController implements RouteHandler
{
    /**
     * Generous, because a legal name is as long as the registry says it is, and
     * refusing a real company's real name is worse than storing a long string.
     */
    private const MAX_LENGTH = 200;

    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);
        $values = [];

        foreach (SupplierDetails::FIELDS as $field) {
            // Every field read the same way, the mandatory ones included: a
            // blank `legal_name` and an absent one are the same omission, and
            // the refusal that names it comes from one place rather than from
            // whichever check happened to run first.
            $values[$field] = $body->optionalNullableString($field, self::MAX_LENGTH);
        }

        $details = $this->desk->setSupplier(
            $context->identity,
            StaffRoute::productCode($request),
            SupplierDetails::parse($values),
        );

        return new JsonResponse(['billing_supplier' => ConfigurationPresenter::supplier($details)], 200);
    }
}
