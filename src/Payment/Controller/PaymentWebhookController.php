<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Domain\WebhookOutcome;
use App\Payment\Service\PaymentWebhook;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/webhooks/payments/{provider} — the source of truth (§24).
 *
 * The only unauthenticated write endpoint in the platform, so it carries its
 * own authentication: the provider's signature over the raw body, checked
 * before a single field is read. Nothing about the request is trusted until
 * that passes, including which payment it claims to concern.
 *
 * It answers 200 to everything it accepted, including a delivery it had
 * already seen and one it chose not to act on. That is not laxity — a
 * provider retries anything that is not a 2xx, so answering an
 * already-handled event with an error is how a retry storm starts. What did
 * or did not happen is in the body, and in `payment_events` for anyone
 * investigating.
 */
final class PaymentWebhookController implements RouteHandler
{
    public function __construct(private readonly PaymentWebhook $webhook)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $outcome = $this->webhook->handle(
            self::providerName($request),
            // The raw bytes, not the parsed body: a signature is over bytes,
            // and re-encoding a parsed structure would produce different
            // ones.
            (string) $request->getBody(),
            self::headers($request),
        );

        return new JsonResponse([
            'outcome' => $outcome->outcome,
            'applied' => $outcome->changedSomething(),
            // Echoed so an operator replaying deliveries by hand can tell
            // which payment each one landed on. It is this platform's own
            // id, and the caller already proved it speaks for the provider
            // that owns the payment.
            'payment_id' => $outcome->paymentId,
        ], self::statusFor($outcome));
    }

    /**
     * 200 for anything understood, 202 for a delivery that was recorded
     * without changing anything. Both are 2xx, so neither is retried; the
     * distinction is for humans reading provider dashboards.
     */
    private static function statusFor(WebhookOutcome $outcome): int
    {
        return $outcome->changedSomething() ? 200 : 202;
    }

    private static function providerName(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('provider');

        return is_string($value) ? $value : '';
    }

    /**
     * Flattened for the adapter, which compares names case-insensitively.
     *
     * array-key rather than string: PHP demotes a numeric-string key to an
     * int, so a header literally named "1" would make this array<int, string>
     * and no promise of string keys can be kept.
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
