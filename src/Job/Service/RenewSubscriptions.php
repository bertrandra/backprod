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
 * **A period, never a term.** Rolling period 7 of a 24-month commitment into
 * period 8 is the agreed contract running; rolling the term into a second 24
 * months is a fresh commitment, which consumer law requires be preceded by a
 * notice whose deadline {@see SendRenewalNotices} says is unconfirmed. So this
 * stops at the term and the term stays a decision somebody takes — the refusals
 * live in `renew()`, where the subscription is, and this pass counts them.
 *
 * **Off unless the operator chose it** ({@see RenewalPolicy}, read per product
 * on every pass, like the dunning schedule). A deployment upgrading into this
 * changes nothing until somebody says so, because what silence would cost here
 * is a customer billed for a period nobody decided to sell them.
 *
 * **Before the period ends, never after.** Selecting what has already lapsed
 * would race `sweep.subscriptions` over the same rows — both daily, and whichever
 * won would decide whether somebody kept their subscription. Inside the lead
 * window there is no race at all, because a subscription still in its period is
 * not lapsed.
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
        foreach ($this->subscriptions->dueForRenewal(self::BATCH, RenewalPolicy::LONGEST_LEAD) as $due) {
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

        return [
            'renewed' => $renewed,
            'not_yet' => $notYet,
            'switched_off' => $switchedOff,
            'refused' => $refused,
            'failed' => $failed,
        ];
    }
}
