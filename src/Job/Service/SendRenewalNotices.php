<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Commerce\Domain\RenewalNotice;
use App\Commerce\Domain\SubscriptionRepository;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Notification\Domain\Category;
use App\Notification\Domain\Notification;
use App\Notification\Service\Notifications;

/**
 * Tells people their subscription is about to renew itself (§13.1, R11).
 *
 * Tacit renewal without the prior notice consumer law requires is the kind of
 * clause that gets a contract term struck out, and the requirement is a
 * *deadline*: a notice sent late is a notice not sent. M5.1 shipped the dates
 * the deadline is computed from and M7.1 shipped a channel that keeps both the
 * attempt and the words. Nothing produced into it. This is that producer, and
 * it is the first one in the platform.
 *
 * **The notice carries legal effect**, so the rendered body is kept as it went
 * out. "Did we tell them, and what did we say?" is the question this exists to
 * answer, and paraphrasing it later from a payload is not an answer.
 *
 * **Sent once per term, by index.** The dedup key is the subscription and the
 * term it is about, so a job that runs nightly through a thirty-day window
 * writes one notice, not thirty — and two runners overlapping write one
 * between them, because uniqueness is a partial unique index and not a check
 * each of them would separately pass.
 *
 * **What it does not do is renew anything.** R11 stays open until the
 * deadlines themselves are confirmed against official sources, especially
 * toward consumers where they vary by member state. Until then a committed
 * subscription still must not auto-renew unattended: this makes the notice
 * possible and observable, it does not make the renewal safe.
 */
final class SendRenewalNotices implements JobHandler
{
    public const TYPE = 'subscription.renewal_notice';

    /**
     * The event name the recipient sees, and the one §27.1's subject line is
     * derived from.
     */
    private const NOTICE_TYPE = 'subscription.renewal_notice';

    /**
     * Recipients, not subscriptions: a tenant with three administrators is
     * three of these. Bounded because a cron pass has to finish, and safe to
     * bound because whatever does not fit is picked up next pass — the dedup
     * key makes re-reading a subscription free.
     */
    private const BATCH = 200;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Notifications $notifications,
    ) {
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
        $raised = 0;
        $alreadyNoticed = 0;
        $unaddressed = 0;

        foreach ($this->subscriptions->dueForRenewalNotice(self::BATCH) as $notice) {
            $recipient = $notice->recipientUserId;

            if ($recipient === null) {
                // A tenant subscription whose tenant has no administrator.
                // Counted rather than skipped: an obligation that reaches
                // nobody is the failure this job exists to prevent, and it
                // must not look like a quiet success.
                $unaddressed++;

                continue;
            }

            if ($this->raise($notice, $recipient) === null) {
                // The index refused it: this term's notice already exists.
                $alreadyNoticed++;

                continue;
            }

            $raised++;
        }

        return [
            'raised' => $raised,
            'already_noticed' => $alreadyNoticed,
            'unaddressed' => $unaddressed,
        ];
    }

    /**
     * Null when the index refused it — this term's notice already exists.
     */
    private function raise(RenewalNotice $notice, string $recipient): ?Notification
    {
        return $this->notifications->raise(
            $notice->tenantId,
            $notice->productId,
            $recipient,
            self::NOTICE_TYPE,
            Category::BILLING,
            [
                'subscription_id' => $notice->subscriptionId,
                'renews_on' => $notice->termEndsAt->format('Y-m-d'),
                'notice_days' => $notice->noticeDays,
            ],
            $notice->dedupKey(),
            true,
        );
    }
}
