<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Stripe;

use App\Billing\Domain\Money;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentProvider;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\ProviderRefund;
use App\Shared\Exceptions\NotConfiguredException;
use App\Shared\Exceptions\UnprocessableEntityException;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\StripeClient;

/**
 * Stripe, behind the port (ADR-048).
 *
 * The first provider that is one. Everything above `PaymentProvider` was
 * built and proven against the stub, so this class is the only place that
 * knows Stripe's shape — the `Stripe\` namespace is reachable from
 * `Infrastructure` and nowhere else (`deptrac.yaml`).
 *
 * **One PaymentIntent per attempt, and it is the payment's id.** `authorize()`
 * creates the intent, so `payments.provider_payment_id` is `pi_…` from the
 * first row and every event Stripe will ever send about that money keys on
 * the same string. That is why the Payment Element rather than hosted
 * Checkout: the hosted page keys on a session and creates the intent later,
 * and an adapter that has to phone the provider from inside a webhook to
 * find its own payment fails closed at the worst moment.
 *
 * **Stripe does not invoice, subscribe or renew.** This platform numbers its
 * own invoices (ADR-021) and runs its own subscriptions; Stripe takes one
 * amount for one attempt and reports what happened to it. Nothing here
 * creates a Stripe Invoice, Subscription or Customer.
 *
 * **No card data.** Nothing this class returns is an instrument. `method` is
 * the kind (`card`, `sepa_debit`), never a number.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public const NAME = 'stripe';

    /**
     * Currencies Stripe counts in thousandths. Stripe refuses an amount in one
     * of them that is not a multiple of 10 (the last digit must be 0); the
     * refusal happens here, in this platform's words, before any request.
     */
    private const THREE_DECIMAL = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    /**
     * @param Closure(): int $now the clock, replaceable so the replay window is testable
     */
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
        private readonly string $publishableKey,
        private readonly bool $livemode,
        private readonly Closure $now,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Whether a secret key belongs to a live account. Sandboxes and test mode
     * issue `sk_test_…`; only production issues `sk_live_…`.
     */
    public static function isLiveKey(string $secretKey): bool
    {
        return str_starts_with($secretKey, 'sk_live_');
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isSandbox(): bool
    {
        return !$this->livemode;
    }

    public function clientKey(): string
    {
        return $this->publishableKey;
    }

    public function authorize(Money $amount, string $reference): ProviderPayment
    {
        $currency = strtolower($amount->currency);

        if (in_array($currency, self::THREE_DECIMAL, true) && $amount->minorUnits % 10 !== 0) {
            throw new UnprocessableEntityException(
                'PAYMENT_AMOUNT_UNSUPPORTED',
                'Stripe counts this currency in thousandths and refuses an amount whose last digit is not zero.',
                ['currency' => $amount->currency, 'minor_units' => $amount->minorUnits],
            );
        }

        try {
            $intent = $this->stripe->paymentIntents->create(
                [
                    'amount' => $amount->minorUnits,
                    'currency' => $currency,
                    // Card, wallets, SEPA and bank redirects as the dashboard
                    // enables them — §24's list — with no branch here per method.
                    'automatic_payment_methods' => ['enabled' => true],
                    // What a human lines the two systems up by: the invoice
                    // number and the attempt (ADR-034), on the dashboard row too.
                    'description' => $reference,
                    'metadata' => ['reference' => $reference],
                ],
                // The reference names the attempt, so a retried call for the
                // same attempt returns the same intent rather than a second one.
                ['idempotency_key' => $this->idempotencyKey('authorize', $reference)],
            );
        } catch (ApiErrorException $failure) {
            throw $this->refused($failure, 'authorize', $reference);
        }

        return new ProviderPayment(
            $intent->id,
            PaymentStatus::PENDING,
            // Short-lived, handed to the page once, never stored (§31).
            is_string($intent->client_secret) ? $intent->client_secret : null,
            // Unknown until the customer chooses; filled from the succeeded event.
            null,
        );
    }

    public function verify(string $rawBody, array $headers): void
    {
        StripeSignature::verify(
            $rawBody,
            self::header($headers, StripeSignature::HEADER),
            $this->webhookSecret,
            ($this->now)(),
        );
    }

    public function parse(string $rawBody): ?ProviderEvent
    {
        return StripeEvents::parse($rawBody, $this->livemode);
    }

    public function refund(Payment $payment, Money $amount, string $reason): ProviderRefund
    {
        try {
            $refund = $this->stripe->refunds->create(
                [
                    'payment_intent' => $payment->providerPaymentId,
                    'amount' => $amount->minorUnits,
                    'reason' => self::refundReason($reason),
                    'metadata' => ['reason' => $reason],
                ],
                // Two identical requests are one refund — the same rule the
                // stub mints its refund id by.
                ['idempotency_key' => $this->idempotencyKey(
                    'refund',
                    $payment->providerPaymentId . '|' . $amount->minorUnits . '|' . $reason,
                )],
            );
        } catch (ApiErrorException $failure) {
            throw $this->refused($failure, 'refund', $payment->providerPaymentId);
        }

        // Settlement arrives by `refund.updated`, as ADR-022 requires.
        return new ProviderRefund($refund->id, 'PENDING');
    }

    /**
     * This platform's three reasons (`Refund::REQUESTED` / `DUPLICATE` /
     * `FRAUDULENT`) onto Stripe's three, which happen to be the same three.
     * Anything else — a word from a future vocabulary — is
     * `requested_by_customer`, with the real word kept in metadata.
     */
    private static function refundReason(string $reason): string
    {
        return match (strtoupper(trim($reason))) {
            'DUPLICATE' => 'duplicate',
            'FRAUDULENT' => 'fraudulent',
            default => 'requested_by_customer',
        };
    }

    /**
     * Keyed on this installation, not only on the subject.
     *
     * Stripe remembers a key for 24 hours and answers a repeat with the
     * first request's result — or a 400 when the parameters differ. The
     * subject is this platform's own reference, `<invoice number>/<attempt>`,
     * and invoice numbers restart at 000001 in every installation and after
     * every database reset. Two deployments on one Stripe account — a laptop
     * with `stripe listen` and a host, a staging and a demo — would therefore
     * hand each other yesterday's intents: an already-paid one, or a refusal
     * because the amounts differ. The first deployment to run beside the
     * developer's own sandbox found exactly that.
     *
     * The webhook secret is what tells installations apart: every endpoint
     * Stripe registers has its own, and an installation without an endpoint
     * of its own is misconfigured regardless. An HMAC over the subject with
     * it reveals nothing about the secret and is stable for as long as the
     * endpoint is — which is what an idempotency key has to be.
     */
    private function idempotencyKey(string $operation, string $subject): string
    {
        return $operation . '_' . hash_hmac('sha256', $subject, $this->webhookSecret);
    }

    /**
     * A Stripe failure, in this platform's envelope and never with Stripe's
     * stack (§31). A key Stripe refuses is configuration, so 503; anything
     * else the request could not be honoured, so 422 with Stripe's code —
     * which is written for merchants, not customers, and stays in `details`.
     *
     * Logged first, at warning, with Stripe's own sentence: it is written for
     * the merchant, which is who reads the log, and without it a refusal is
     * a code and a request id that nobody can act on. No key, no card, no
     * customer is in it.
     */
    private function refused(ApiErrorException $failure, string $operation, string $subject): \RuntimeException
    {
        $this->logger->warning('Stripe refused a request', [
            'operation' => $operation,
            'reference' => $subject,
            'stripe_code' => $failure->getStripeCode(),
            'http_status' => $failure->getHttpStatus(),
            'message' => $failure->getMessage(),
        ]);

        if ($failure instanceof AuthenticationException || $failure instanceof PermissionException) {
            return new NotConfiguredException(
                'PAYMENT_PROVIDER_REJECTED_KEY',
                'The payment provider refused this deployment\'s credentials.',
            );
        }

        if ($failure instanceof ApiConnectionException) {
            return new NotConfiguredException(
                'PAYMENT_PROVIDER_UNREACHABLE',
                'The payment provider could not be reached.',
            );
        }

        $code = $failure->getStripeCode();

        return new UnprocessableEntityException(
            'PAYMENT_PROVIDER_REFUSED',
            'The payment provider refused the request.',
            ['provider_code' => is_string($code) ? $code : null],
        );
    }

    /**
     * @param array<array-key, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
