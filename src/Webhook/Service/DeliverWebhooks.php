<?php

declare(strict_types=1);

namespace App\Webhook\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Webhook\Domain\WebhookDeliveries;
use App\Webhook\Domain\WebhookDelivery;
use App\Webhook\Domain\WebhookEndpoints;
use App\Webhook\Domain\WebhookSignature;
use App\Webhook\Domain\WebhookTransport;
use DateTimeImmutable;
use Throwable;

/**
 * Sends what the outbox holds, from the queue (ADR-051 §5).
 *
 * Never inside the request that caused the event: a product whose server is
 * down would make the platform slow, and a product that answers 500 would
 * make a membership change fail. So the act writes the row, and this runs
 * later, from cron, like {@see \App\Notification\Service\DispatchNotifications}.
 *
 * **Idempotent by construction, not by care.** ADR-027 requires handlers
 * that can run twice. Here the claim moves a row's next attempt into the
 * future in the statement that selects it, so two passes never hold the
 * same row; and the product deduplicates by `event_id`, which is why a
 * delivery that timed out after the product wrote is a duplicate the
 * product will see and must be ready for.
 *
 * A `2xx` within ten seconds is delivered. Anything else is retried on the
 * schedule §5 fixes — 1 min, 10 min, 1 h, 6 h, 24 h — and then parked,
 * visible in the console with its last answer; a `400` parks at once,
 * because a product that read the delivery and refused it will refuse it
 * again. Parked is not lost: the console puts it back on the queue.
 */
final class DeliverWebhooks implements JobHandler
{
    public const TYPE = 'webhook.deliver';

    private const BATCH = 50;

    /**
     * Sized against the whole pass, as the notification dispatcher's is: the
     * batch is claimed in one statement and sent one at a time, each with up
     * to ten seconds to answer.
     */
    private const LEASE_SECONDS = 900;

    /** Seconds until the next attempt, by the attempt that just failed. */
    private const BACKOFF = [60, 600, 3_600, 21_600, 86_400];

    public function __construct(
        private readonly WebhookDeliveries $deliveries,
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookTransport $transport,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        $collected = $this->deliveries->collectSubscriptionEvents();

        $delivered = 0;
        $failed = 0;
        $parked = 0;

        foreach ($this->deliveries->claimDue(self::BATCH, self::LEASE_SECONDS) as $delivery) {
            match ($this->send($delivery)) {
                'delivered' => $delivered++,
                'failed' => $failed++,
                default => $parked++,
            };
        }

        return ['collected' => $collected, 'delivered' => $delivered, 'failed' => $failed, 'parked' => $parked];
    }

    /**
     * @return 'delivered'|'failed'|'parked'
     */
    private function send(WebhookDelivery $delivery): string
    {
        try {
            $endpoint = $this->endpoints->endpoint($delivery->productId);

            if ($endpoint === null) {
                // The address or the secret went away after the row was
                // written. Retrying will not bring either back; an operator
                // will, and then retry from the console.
                $this->deliveries->recordFailure($delivery->id, null, 'NO_ENDPOINT', null);

                return 'parked';
            }

            $body = json_encode(self::envelope($delivery), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $answer = $this->transport->post($endpoint->url, $body, [
                'Content-Type' => 'application/json',
                'User-Agent' => 'backprod-webhooks/1',
                WebhookSignature::HEADER => WebhookSignature::header($body, $endpoint->secrets, time()),
                'X-Backprod-Event' => $delivery->eventType,
                'X-Backprod-Delivery' => $delivery->id,
            ]);
        } catch (Throwable $error) {
            // The class, never the message (§31): a transport's message can
            // quote the URL, and this column is served to the console.
            $this->deliveries->recordFailure($delivery->id, null, substr($error::class, 0, 200), null);

            return 'parked';
        }

        if ($answer->delivered()) {
            $this->deliveries->recordDelivered($delivery->id, $answer->status ?? 200);

            return 'delivered';
        }

        $error = $answer->error ?? ('HTTP_' . $answer->status);
        $next = $answer->status === 400 ? null : self::nextAttempt($delivery->attempt);

        $this->deliveries->recordFailure($delivery->id, $answer->status, $error, $next);

        return $next === null ? 'parked' : 'failed';
    }

    /**
     * The payload as ADR-051 §5 describes it: an envelope naming the event,
     * the product and the tenant, and the detail the act recorded.
     *
     * @return array<string, mixed>
     */
    private static function envelope(WebhookDelivery $delivery): array
    {
        return [
            'event_id' => $delivery->eventId,
            'type' => $delivery->eventType,
            'occurred_at' => $delivery->occurredAt->format(DATE_ATOM),
            'product' => $delivery->productCode,
            'tenant_id' => $delivery->tenantId,
        ] + $delivery->payload;
    }

    /**
     * Null once the schedule is spent: the attempt just made was the last.
     */
    private static function nextAttempt(int $attempt): ?DateTimeImmutable
    {
        $delay = self::BACKOFF[$attempt - 1] ?? null;

        return $delay === null ? null : (new DateTimeImmutable())->modify(sprintf('+%d seconds', $delay));
    }
}
