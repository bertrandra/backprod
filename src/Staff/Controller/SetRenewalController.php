<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\RenewalPolicy;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/configuration/renewal?product=CODE — whether this product's
 * subscriptions renew by themselves, and how many days before the period ends
 * the customer is invoiced and asked to pay (2026-10-05).
 *
 * The setting existed from ADR-068 and nothing on the platform could write it:
 * the renewal pass read `renewal` from `product_configuration` and only a hand
 * edit of the row could switch it on. The operator asked for it where the rest
 * of a product's commercial settings are — *« J-7 ou X jours : paramètre dans
 * la console »*.
 *
 * Both fields are required. Off is a choice and has to be said, and a lead
 * outside 1–30 days is refused rather than stored: the pass treats an unusable
 * document as no document, so storing one would answer 200 and switch renewal
 * off — a saved form that silently did the opposite.
 */
final class SetRenewalController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);
        $policy = RenewalPolicy::chosen(
            $body->requiredBool('automatic'),
            $body->requiredInt('lead_days'),
        );

        if ($policy === null) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'lead_days', 'requirement' => 'must be between 1 and ' . RenewalPolicy::LONGEST_LEAD],
            );
        }

        $renewal = $this->desk->setRenewal($context->identity, StaffRoute::productCode($request), $policy);

        return new JsonResponse(['renewal' => ConfigurationPresenter::renewal($renewal)], 200);
    }
}
