<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Commerce\Domain\RenewalPolicy;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Service\Subscriptions;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Product\Domain\ProductSettings;
use App\Shared\Exceptions\HttpException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rolls a subscription into its next paid period, and bills for it (ADR-068).
 *
 * The thing R11 left out and ADR-067 made visible: `Subscriptions::renew()` was
 * written, tested and reachable by nothing — no endpoint, no job — so every
 * subscription on this platform ended at its first paid period whatever its term
 * said. ADR-067 started the sweep that moves the column to `EXPIRED`, which did
 * not create that problem but does display it.
 *
 * **A period, and at the term the term** (ADR-071). Rolling period 7 of a
 * 24-month commitment into period 8 is the agreed contract running; at the end
 * of the term, an offer sold `AUTO_RENEW` rolls into the next term with its
 * first period, once the notice {@see SendRenewalNotices} raised is at least
 * `notice_days` old. The refusals live in `renew()`, where the subscription
 * is, and this pass counts them — `TERM_RENEWAL_NOT_NOTICED` among them.
 *
 * **On unless the operator switched it off** ({@see RenewalPolicy}, read per
 * product on every pass, like the dunning schedule). The operator's baseline
 * since 2026-10-29: a subscription is sold as continuing.
 *
 * **Before the period ends, never after.** Selecting what has already lapsed
 * would race `sweep.subscriptions` over the same rows — both daily, and whichever
 * won would decide whether somebody kept their subscription. Inside the lead
 * window there is no race at all, because a subscription still in its period is
 * not lapsed. The one exception is arrears under a commitment (ADR-072): they
 * renew, are billed and stay `PAST_DUE`, and one cron missed is caught up here
 * even after the period ended — the sweep never takes those rows, so the two
 * still share none.
 *
 * **Nothing here decides anything about one subscription.** It asks `renew()`,
 * which holds every rule in order: a cancellation already due, a change the
 * customer asked for, an offer sold as ending at its term, the term itself, a
 * period that has no price. Deciding any of that here would be a second place it
 * could be decided from, and the two would come apart.
 *
 * **One refusal is not a failure.** A subscription the pass may not renew is
 * counted by its reason and the pass carries on — the same rule the runner
 * applies around a handler. A refusal is a `ConflictException` and the answer is
 * its code; anything else is logged by class, never by message (§31).
 */
final class RenewSubscriptions implements JobHandler
{
    public const TYPE = 'subscription.renewal';

    /**
     * How many subscriptions one pass looks at.
     *
     * Bounded because a cron run has to finish, and safe to bound because the
     * lead window is days wide: whatever does not fit is still inside it next
     * pass. Smaller than the notice job's 200 because each of these writes an
     * invoice and a fiscal fact rather than a notification.
     */
    private const BATCH = 50;

    /**
     * Pages per pass (2026-10-29). One page of fifty was the whole day's
     * renewing, and rows the pass skips — another product's lead, renewal
     * switched off, a refusal — kept their place at its head, so the rest
     * lapsed. Bounded still, because a cron run has to finish; whatever is
     * left is inside the lead window tomorrow.
     */
    private const PAGES = 20;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Subscriptions $service,
        private readonly ProductSettings $settings,
        private readonly LoggerInterface $logger,
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
        $renewed = 0;
        $notYet = 0;
        $switchedOff = 0;
        $refused = [];
        $failed = 0;

        /** @var array<string, RenewalPolicy> $policies by product, so one pass reads each product's once */
        $policies = [];

        // The longest lead any product could have chosen bounds the read; each
        // product's own answer decides. The query cannot do it: the lead is a
        // product's and the query is not.
        for ($page = 0; $page < self::PAGES; $page++) {
            $batch = $this->subscriptions->dueForRenewal(self::BATCH, RenewalPolicy::LONGEST_LEAD, $page * self::BATCH);

            foreach ($batch as $due) {
                $policy = $policies[$due->productId]
                    ??= RenewalPolicy::fromConfiguration($this->settings->all($due->productId));

                if (!$policy->automatic) {
                    // This product does not renew by itself. Counted rather than
                    // skipped silently: "nothing to renew" and "nobody asked me to"
                    // are different answers, and an operator who switched this on
                    // for one product of five needs to see the other four.
                    $switchedOff++;

                    continue;
                }

                if ($due->daysUntilEnd > $policy->leadDays) {
                    // Inside the read's bound but outside this product's lead.
                    $notYet++;

                    continue;
                }

                try {
                    $this->service->renew($due->tenantId, $due->productId, $due->subscriberUserId);
                    $renewed++;
                } catch (HttpException $refusal) {
                    // A rule said no, and which rule is the whole of the answer: a
                    // term reached, a cancellation already due, a period with no
                    // price, or another pass that got there first. Counted by code
                    // so a pass that renews nothing says why rather than looking
                    // broken.
                    $code = $refusal->errorCode();
                    $refused[$code] = ($refused[$code] ?? 0) + 1;
                } catch (Throwable $error) {
                    // Not a refusal: something broke. The class and never the
                    // message (§31), and the pass carries on — one subscription's
                    // billing chain failing must not cost the rest theirs.
                    $failed++;

                    $this->logger->error('A subscription could not be renewed', [
                        'subscription_id' => $due->subscriptionId,
                        'error' => $error::class,
                    ]);
                }
            }

            if (count($batch) < self::BATCH) {
                break;
            }
        }

        return [
            'renewed' => $renewed,
            'not_yet' => $notYet,
            'switched_off' => $switchedOff,
            'refused' => $refused,
            'failed' => $failed,
        ];
    }
}
