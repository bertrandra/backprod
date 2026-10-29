<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Commerce\Domain\RenewalNotice;
use App\Commerce\Domain\RenewalPolicy;
use App\Commerce\Domain\SubscriptionRepository;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Notification\Domain\Category;
use App\Notification\Domain\Notification;
use App\Notification\Service\Notifications;
use App\Product\Domain\ProductSettings;
use DateTimeImmutable;

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
 * **What it does not do is renew anything.** `subscription.renewal` rolls the
 * term, and only once it finds this notice, raised at least `notice_days`
 * before (ADR-071). That is the operator's decision of 2026-10-29: tacit
 * renewal is the baseline, and this notice is its precondition rather than
 * a courtesy beside it. What R11 still records is that the deadlines
 * themselves — a product's `notice_days` — were never checked against an
 * official source per member state; the offer's figure is the one applied.
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

    /**
     * How far past `notice_days` the read reaches before a term's end: the
     * longest renewal lead, plus the month a term's last period can end short
     * of it. The handler decides the day; the read only has to hold it.
     */
    private const AHEAD = RenewalPolicy::LONGEST_LEAD + 31;

    /**
     * Days of slack before the notice is strictly due, so a nightly pass and
     * the mail queue behind it still deliver it `notice_days` ahead.
     */
    private const SLACK_DAYS = 2;

    /** Pages per pass, so a pass finishes; whatever is left is read tomorrow. */
    private const PAGES = 10;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Notifications $notifications,
        private readonly ProductSettings $settings,
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
        $notYet = 0;
        $switchedOff = 0;
        $unpriceable = 0;

        /** @var array<string, RenewalPolicy> $policies */
        $policies = [];
        $today = new DateTimeImmutable('today');

        for ($page = 0; $page < self::PAGES; $page++) {
            $batch = $this->subscriptions->dueForRenewalNotice(self::BATCH, self::AHEAD, $page * self::BATCH);

            foreach ($batch as $notice) {
                $policy = $policies[$notice->productId]
                    ??= RenewalPolicy::fromConfiguration($this->settings->all($notice->productId));

                if (!$policy->automatic) {
                    // Nothing will renew it, so there is nothing to warn
                    // about, and a notice would promise what will not happen.
                    $switchedOff++;

                    continue;
                }

                $renewsOn = $notice->renewsOn();

                if ($renewsOn === null) {
                    // No period length, so no renewal can be priced.
                    $unpriceable++;

                    continue;
                }

                // The renewal is decided a lead before the next term starts;
                // that is the day the customer must refuse by, and the notice
                // must reach them `notice_days` before it (ADR-071).
                $cancelBy = $renewsOn->modify(sprintf('-%d days', $policy->leadDays))->setTime(0, 0);

                if ($today < $cancelBy->modify(sprintf('-%d days', $notice->noticeDays + self::SLACK_DAYS))) {
                    $notYet++;

                    continue;
                }

                $recipient = $notice->recipientUserId;

                if ($recipient === null) {
                    // A subscription with nobody to tell. Counted rather than
                    // skipped: an obligation that reaches nobody is the
                    // failure this job exists to prevent, and it must not look
                    // like a quiet success.
                    $unaddressed++;

                    continue;
                }

                if ($this->raise($notice, $recipient, $renewsOn, $cancelBy) === null) {
                    // The index refused it: this term's notice already exists.
                    $alreadyNoticed++;

                    continue;
                }

                $raised++;
            }

            if (count($batch) < self::BATCH) {
                break;
            }
        }

        return [
            'raised' => $raised,
            'already_noticed' => $alreadyNoticed,
            'unaddressed' => $unaddressed,
            'not_yet' => $notYet,
            'switched_off' => $switchedOff,
            'unpriceable' => $unpriceable,
        ];
    }

    /**
     * Null when the index refused it — this term's notice already exists.
     */
    private function raise(
        RenewalNotice $notice,
        string $recipient,
        DateTimeImmutable $renewsOn,
        DateTimeImmutable $cancelBy,
    ): ?Notification {
        return $this->notifications->raise(
            $notice->tenantId,
            $notice->productId,
            $recipient,
            self::NOTICE_TYPE,
            Category::BILLING,
            [
                'subscription_id' => $notice->subscriptionId,
                // The day the next term starts, and the day to refuse by.
                // The renewal reads `cancel_by` back as its precondition, so
                // what the customer was told and what is enforced are one date.
                'renews_on' => $renewsOn->format('Y-m-d'),
                'cancel_by' => $cancelBy->format('Y-m-d'),
                'notice_days' => $notice->noticeDays,
            ],
            $notice->dedupKey(),
            true,
        );
    }
}
