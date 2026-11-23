<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ContactRequest;
use App\Product\Service\ContactDesk;
use App\Shared\Context\ClientAddress;
use App\Shared\Http\JsonBody;
use App\Shared\Http\ReaderLanguage;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /public/products/{code}/contact` — a visitor writes to the people
 * behind a product (2026-11-23).
 *
 * Public, because a visitor has no account; refused where the published
 * page offers no form. Answers `202` with nothing in it, the same answer
 * for a message kept and for one the hidden field threw away, so a robot
 * learns nothing from the difference.
 */
final class SendContactRequestController implements RouteHandler
{
    /**
     * @param list<string> $trustedProxies the proxies whose `X-Forwarded-For`
     *                                     is believed, as the rate limiter's
     */
    public function __construct(
        private readonly ContactDesk $desk,
        private readonly array $trustedProxies = [],
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $code = $request->getAttribute('code');
        $body = JsonBody::of($request);

        $this->desk->receive(
            is_string($code) ? $code : '',
            $body->requiredString('name', ContactRequest::LONGEST_NAME),
            $body->requiredString('email', ContactRequest::LONGEST_EMAIL),
            $body->requiredString('message', ContactRequest::LONGEST_MESSAGE),
            $body->requiredBool('consent'),
            $body->has('website') ? ($body->optionalNullableString('website', 200) ?? '') : '',
            ReaderLanguage::of($request),
            ClientAddress::of($request, $this->trustedProxies),
        );

        return new JsonResponse(['received' => true], 202, ['Cache-Control' => 'no-store']);
    }
}
