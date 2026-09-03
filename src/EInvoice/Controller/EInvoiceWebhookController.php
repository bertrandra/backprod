<?php

declare(strict_types=1);

namespace App\EInvoice\Controller;

use App\EInvoice\Service\EInvoiceWebhook;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/webhooks/einvoice/{provider} — the platform's verdicts.
 *
 * Unauthenticated like the payment webhook, and authenticating itself the
 * same way: the platform's signature over the raw body, checked before a
 * field is read. It answers 2xx to everything it accepted, including a
 * delivery it had already seen, because a platform retries anything else.
 */
final class EInvoiceWebhookController implements RouteHandler
{
    public function __construct(private readonly EInvoiceWebhook $webhook)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $outcome = $this->webhook->handle(
            self::providerName($request),
            (string) $request->getBody(),
            self::headers($request),
        );

        return new JsonResponse([
            'outcome' => $outcome->outcome,
            'applied' => $outcome->changedSomething(),
            'transmission_id' => $outcome->transmissionId,
        ], $outcome->changedSomething() ? 200 : 202);
    }

    private static function providerName(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('provider');

        return is_string($value) ? $value : '';
    }

    /**
     * array-key rather than string: PHP demotes a numeric-string key to an
     * int, so no promise of string keys can be kept.
     *
     * @return array<array-key, string>
     */
    private static function headers(ServerRequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return $headers;
    }
}
