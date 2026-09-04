<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\Delivery;
use App\Notification\Domain\Notification;
use App\Notification\Domain\NotificationRepository;
use App\Notification\Domain\Notifier;
use App\User\Domain\UserRepository;
use Throwable;

/**
 * Sends what is waiting, from the queue (§27.1, ADR-027).
 *
 * Sending inside the HTTP request would make a slow SMS provider a slow API
 * and an unreachable one an unreachable API. So producers write the
 * notification in their own transaction, and this runs later, from cron.
 *
 * **Exactly-once is the index, not this code.** ADR-027 requires idempotent
 * handlers because an expired lease lets two copies of a job finish — and for
 * a notification a duplicate is a billed SMS and an annoyed recipient. The
 * claim moves a delivery out of PENDING, and `UNIQUE (notification_id,
 * channel)` means there was never more than one row to claim in the first
 * place. Two runners racing take different rows or none.
 *
 * **A suppression is written, never skipped.** A delivery the gate refuses
 * becomes SUPPRESSED with its reason, because "we did not send it" and "we
 * never tried" are different answers to the question a pre-renewal notice
 * (§13.1) has to settle.
 */
final class DispatchNotifications implements JobHandler
{
    public const TYPE = 'notify.dispatch';

    /**
     * How many deliveries one pass takes. Bounded because a cron run has to
     * finish: a queue that grew faster than a pass could drain it would never
     * reach the newest item.
     */
    private const BATCH = 50;

    /**
     * @var array<string, Notifier>
     */
    private readonly array $channels;

    /**
     * @param iterable<Notifier> $notifiers
     */
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Notifications $service,
        private readonly UserRepository $users,
        iterable $notifiers,
    ) {
        $byChannel = [];

        foreach ($notifiers as $notifier) {
            $byChannel[$notifier->channel()] = $notifier;
        }

        $this->channels = $byChannel;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Job $job): array
    {
        $sent = 0;
        $suppressed = 0;
        $failed = 0;

        foreach ($this->notifications->claimPending(self::BATCH) as $claim) {
            $outcome = $this->deliver($claim['delivery'], $claim['notification']);

            match ($outcome) {
                Delivery::SENT => $sent++,
                Delivery::SUPPRESSED => $suppressed++,
                default => $failed++,
            };
        }

        return ['sent' => $sent, 'suppressed' => $suppressed, 'failed' => $failed];
    }

    private function deliver(Delivery $delivery, Notification $notification): string
    {
        $channel = $delivery->channel;
        $notifier = $this->channels[$channel] ?? null;

        if ($notifier === null) {
            // A channel with no adapter wired is not a failure to retry: no
            // amount of retrying will configure it.
            $this->notifications->recordSuppressed($delivery->id, Delivery::CHANNEL_UNAVAILABLE);

            return Delivery::SUPPRESSED;
        }

        $address = $this->addressFor($notification->recipientUserId, $channel);

        $refusal = $this->service->gateFor($notification->recipientUserId, $notification->productId)->refuse(
            $notification->category,
            $channel,
            $this->notifications->hasLiveConsent(
                $notification->recipientUserId,
                $channel,
                Category::purposeOf($notification->category),
            ),
            $address !== '',
        );

        if ($refusal !== null) {
            $this->notifications->recordSuppressed($delivery->id, $refusal);

            return Delivery::SUPPRESSED;
        }

        $subject = self::subjectFor($notification);
        $body = self::bodyFor($notification);

        try {
            $providerMessageId = $notifier->send($address, $subject, $body, $notification->payload);
        } catch (Throwable $error) {
            // The class of the error, never its message (§31): a provider's
            // message can carry an endpoint or a token, and this field is
            // served over the API. The message goes to the log.
            $this->notifications->recordFailure($delivery->id, $error::class);

            return Delivery::FAILED;
        }

        // The rendered text is kept only where the notification has legal
        // effect — a pre-renewal notice, a formal demand. What can later be
        // relied on must stay re-readable as it was sent; everything else is
        // rendered fresh from the payload each time.
        $this->notifications->recordSent(
            $delivery->id,
            $providerMessageId,
            $notification->legalEffect ? $body : null,
        );

        return Delivery::SENT;
    }

    /**
     * Where to reach somebody on a channel.
     *
     * Only email is resolvable today: a phone number is not part of the
     * platform's identity model yet, so SMS and WhatsApp have no address and
     * are recorded NO_ADDRESS rather than attempted. That is the honest
     * answer, and it is visible in the delivery record instead of being a
     * silent nothing.
     */
    private function addressFor(string $userId, string $channel): string
    {
        if (Channel::isInternal($channel)) {
            // The row itself is the delivery; there is nothing to address.
            return $channel;
        }

        if ($channel !== Channel::EMAIL) {
            return '';
        }

        return $this->users->find($userId)?->email ?? '';
    }

    /**
     * The subject line, from the type rather than from stored text.
     *
     * `payment.failed` becomes "Payment failed". Crude, and deliberately so:
     * a real template catalogue with locales belongs with the frontend that
     * owns the wording, and inventing one here would freeze English into the
     * backend.
     */
    private static function subjectFor(Notification $notification): string
    {
        $words = str_replace(['.', '_'], ' ', $notification->type);

        return ucfirst($words);
    }

    private static function bodyFor(Notification $notification): string
    {
        $lines = [self::subjectFor($notification)];

        foreach ($notification->payload as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = sprintf('%s: %s', str_replace('_', ' ', (string) $key), (string) $value);
            }
        }

        return implode("\n", $lines);
    }
}
