<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Commerce\Domain\DunningSchedule;
use App\Commerce\Domain\OverdueSubscription;
use App\Commerce\Domain\SubscriptionRepository;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Notification\Domain\Category;
use App\Notification\Service\Notifications;
use App\Payment\Service\Payments;
use App\Product\Domain\ProductSettings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chasing an invoice a customer has not paid (spec §5.2).
 *
 * **From the queue, never inside an HTTP request.** The rule is §27.1's and
 * this is where it costs the most: a chase talks to a payment provider *and*
 * writes a notice, so doing it in a request would put a provider's latency on
 * whatever endpoint happened to notice the debt — and there is no such
 * endpoint, because noticing is something the clock does. Nothing in `src/`
 * calls this: cron claims the job, and `bin/run-jobs.php` is the only entry
 * point.
 *
 * **The schedule is the product's** ({@see DunningSchedule}), read from
 * `product_configuration` on every pass. A constant would make changing a
 * commercial delay a deployment, and two products have no reason to chase at the
 * same rhythm. The default — J+1, J+3, J+7, then stop — is a default and not the
 * rule, which is the whole point of putting it where an operator can reach it.
 *
 * **One pass does three things, in this order, per debt:**
 *
 * ```text
 * 1. declare the arrears      the workshop shuts; once, by status in the WHERE
 * 2. raise the notice         claims this step, by unique index
 * 3. start a fresh attempt    a new payment row, never an old one revived
 * ```
 *
 * **Why the notice comes before the attempt.** A crash between 2 and 3 loses one
 * attempt and the customer is still told, which they can act on — paying from
 * the invoice screen is the only way the money can actually arrive, since this
 * platform holds no instrument (§24: a card never reaches it). A crash between
 * 3 and 2 would leave money asked for and nobody told, and "did we tell them?"
 * would answer no for ever. The cheaper failure is the one to choose.
 *
 * **Exactly once per step, by index and not by a check.** The notification's
 * dedup key is the invoice and the step, so a pass that runs every night through
 * a seven-day window writes three notices and not seven, and two runners
 * overlapping write one between them. A prior "have we chased this?" would be a
 * read two passes both pass.
 *
 * **What an attempt can be, honestly.** §24 keeps the instrument outside this
 * platform, so there is no card here to charge off-session: an attempt is a
 * fresh authorization the customer completes from the link in the notice. It is
 * a new `payments` row every time — "a new attempt, never the same one revived",
 * the rule `Checkout::retry` already states, because `PaymentStatus` is one-way
 * and two attempts that must be told apart cannot share a provider reference.
 *
 * **Stopping is the absence of a next step.** Past the last retry day the
 * schedule keeps answering the last step, its notice already exists, and the
 * index refuses a second — so "then stop" needs no state and no flag. What it
 * deliberately does *not* do is cancel the subscription: ending a customer's
 * contract is a decision (§13.1), with a rule, an effective date and months
 * owed, and a collection pass is not where one gets taken.
 */
final class CollectOverdueInvoices implements JobHandler
{
    public const TYPE = 'subscription.dunning';

    /**
     * The event the recipient sees, and what `MailWording` gives words to.
     *
     * A notification and not a message (§12.3): a chase is one-way, and putting
     * it in a support conversation would put system noise in a thread with
     * participants, an order and a reply.
     */
    public const NOTICE_TYPE = 'subscription.payment_overdue';

    /**
     * Recipients, not debts: an organisation's subscription with three
     * administrators is three of these. Bounded because a cron pass has to
     * finish, and safe to bound because whatever does not fit is picked up next
     * pass — the dedup key makes re-reading a debt free.
     */
    private const BATCH = 200;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Notifications $notifications,
        private readonly Payments $payments,
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
        $suspended = 0;
        $chased = 0;
        $alreadyChased = 0;
        $withinGrace = 0;
        $unaddressed = 0;

        /** @var array<string, DunningSchedule> $schedules by product, so one pass reads each product's once */
        $schedules = [];

        /**
         * Debts this pass has already asked the provider about.
         *
         * `overdue()` answers one row **per recipient**, so an organisation
         * with three administrators is three rows about one invoice — three
         * notices, which is right, and one attempt, which is what this keeps
         * true. Without it the pass would authorize three payments for one
         * debt and the customer would find three intents against one document.
         *
         * @var array<string, true> keyed by invoice and step
         */
        $asked = [];

        foreach ($this->subscriptions->overdue(self::BATCH) as $debt) {
            $schedule = $schedules[$debt->productId]
                ??= DunningSchedule::fromConfiguration($this->settings->all($debt->productId));

            $step = $schedule->stepDueAfter($debt->daysOverdue);

            if ($step === null) {
                // Inside the grace the operator chose. Not in arrears yet, and
                // deliberately nothing is written: an invoice issued and paid
                // within the hour must leave no trace of having been chased.
                $withinGrace++;

                continue;
            }

            if (!$debt->alreadyInArrears && $this->subscriptions->declareArrears($debt->subscriptionId, $debt->invoiceId)) {
                $suspended++;
            }

            if ($debt->recipientUserId === null) {
                // An organisation's subscription whose tenant has no
                // administrator. Counted rather than skipped: a debt nobody was
                // told about must not read as nothing to collect. The
                // suspension above still stands — the money is owed whether or
                // not there is anybody to write to.
                $unaddressed++;

                continue;
            }

            if (!$this->tell($debt, $step, $debt->recipientUserId)) {
                // The index refused it: this step's notice already exists, so
                // no second attempt is started either. This is what keeps a
                // nightly pass from authorizing a payment every night.
                $alreadyChased++;

                continue;
            }

            $chased++;

            if (isset($asked[$debt->dedupKey($step)])) {
                // A colleague of theirs was told about this same debt a moment
                // ago and the money has already been asked for. They still get
                // their own notice — that is why the loop reached here — and
                // one debt is asked for once.
                continue;
            }

            $asked[$debt->dedupKey($step)] = true;
            $this->attempt($debt);
        }

        return [
            'suspended' => $suspended,
            'chased' => $chased,
            'already_chased' => $alreadyChased,
            'within_grace' => $withinGrace,
            'unaddressed' => $unaddressed,
        ];
    }

    /**
     * Raises the notice for one step, or false when it already existed.
     *
     * **Legal effect, so the rendered body is kept** (§5.2): a chase is a formal
     * demand, and "did we tell them, and what did we say?" is the question it
     * exists to answer. Paraphrasing it afterwards from a payload is not an
     * answer, for the reason an invoice keeps its snapshot.
     *
     * The payload carries what the words need and nothing else — no token, no
     * card, no provider message, no exception (§31). A channel leaves the
     * platform, and `days_overdue` and an invoice number leave nothing behind
     * that matters if it does.
     */
    private function tell(OverdueSubscription $debt, int $step, string $recipient): bool
    {
        return $this->notifications->raise(
            $debt->tenantId,
            $debt->productId,
            $recipient,
            self::NOTICE_TYPE,
            Category::BILLING,
            [
                'invoice_id' => $debt->invoiceId,
                // The number when it has one; an invoice with none is not a
                // document anybody can be asked to settle, and the repository
                // only returns issued ones.
                'invoice_number' => $debt->invoiceNumber ?? '',
                'due_on' => $debt->dueAt->format('Y-m-d'),
                'days_overdue' => $debt->daysOverdue,
                'attempt' => $step,
            ],
            $debt->dedupKey($step),
            true,
        ) !== null;
    }

    /**
     * A fresh attempt at the invoice, and a failure to start one is not a
     * failure of the pass.
     *
     * The provider being unreachable, or refusing, must not abort the pass: the
     * notice is already written, the rest of the batch is other people's debts,
     * and a throw here would fail the job and retry the whole batch — which the
     * dedup index would then answer with "already chased", so the attempt would
     * never be made anyway. Logged, because an operator needs to know the
     * provider is down; not on the notification, because §31 keeps a provider's
     * words out of anything that leaves the platform.
     *
     * No actor: nobody started this, the schedule did.
     */
    private function attempt(OverdueSubscription $debt): void
    {
        try {
            $this->payments->start($debt->tenantId, $debt->productId, $debt->invoiceId, null);
        } catch (Throwable $error) {
            $this->logger->warning('A dunning attempt could not be started.', [
                'invoice_id' => $debt->invoiceId,
                'subscription_id' => $debt->subscriptionId,
                'error' => $error::class,
            ]);
        }
    }
}
