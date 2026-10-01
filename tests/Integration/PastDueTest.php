<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Commerce\Domain\DunningSchedule;
use App\Commerce\Service\Subscriptions;
use App\Job\Domain\Job;
use App\Job\Service\CollectOverdueInvoices;
use App\Notification\Service\DispatchNotifications;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Exceptions\ConflictException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * An unpaid invoice suspends the workshop and nothing else (spec §5).
 *
 * Four claims, and each one is a decision the operator took rather than a
 * mechanism:
 *
 *   1. an unpaid invoice **shuts the product** and leaves the documents
 *      readable — the customer must still reach the screen they pay at;
 *   2. the refusal is **its own**, and not "your organisation does not cover
 *      you", which would send the holder of a seat to ask a colleague for a
 *      place they already hold while the invoice stayed unpaid;
 *   3. a chase is a **new attempt** and a **notification**, raised from the
 *      queue and never inside a request;
 *   4. the schedule comes from the **product's configuration**, not a
 *      constant.
 *
 * Against the real database throughout. The status is a real column with a real
 * CHECK, the scope indexes are partial on it, and "how many days overdue" is
 * computed by PostgreSQL from the invoice's own dates — a double would be a
 * second implementation of all three, and the test would then check that my two
 * versions agree.
 */
#[CoversNothing]
final class PastDueTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $owner = '';
    private string $colleague = '';
    private string $offer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->owner = $this->person('sub-ada', 'ada@acme.test');
        $this->colleague = $this->person('sub-bo', 'bo@acme.test');

        $this->seedCatalogue();

        $membership = fn (string $userId, bool $administrator): TenantMembership => new TenantMembership(
            $this->tenant,
            $userId,
            $this->product,
            [$administrator ? 'TENANT_ADMIN' : 'USER'],
            $administrator
                ? ['projects.read', 'projects.write', 'subscription.read', 'subscription.manage', 'billing.read', 'payments.read']
                : ['projects.read', 'projects.write', 'subscription.read', 'billing.read', 'payments.read'],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'bo-token' => 'sub-bo',
            ]),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                $membership($this->owner, true),
                $membership($this->colleague, false),
            ]),
            // A chase asks the provider for a fresh authorization, so there has
            // to be one: with none configured the platform cannot take money at
            // all, which is fail-closed and would make "a chase is an attempt"
            // untestable rather than false.
            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider('dunning-secret')]),
        ]);
    }

    // --- 1. what a suspension does, and what it deliberately does not ---------

    /**
     * The whole of §5.1 in one test: the workshop is shut, the documents are
     * not.
     *
     * The second half is the one that would be forgotten. Suspending the
     * invoices and payments as well would shut the door of the screen the
     * customer came to pay at, and the suspension would then be unrecoverable
     * from inside the product.
     */
    public function testAnUnpaidInvoiceShutsTheWorkshopAndLeavesTheDocumentsReadable(): void
    {
        $subscription = $this->subscribe();

        self::assertSame(200, $this->projects('ada-token')->getStatusCode(), 'covered before anything is owed');

        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        self::assertSame('PAST_DUE', $this->statusOf($subscription));
        self::assertSame(403, $this->projects('ada-token')->getStatusCode());

        // And the documents, which are the point.
        self::assertSame(200, $this->get('/api/v1/billing/invoices', 'ada-token')->getStatusCode());
        self::assertSame(200, $this->get('/api/v1/billing/payments', 'ada-token')->getStatusCode());
        // Including the subscription itself, which is where the banner lives.
        self::assertSame(200, $this->get('/api/v1/subscription', 'ada-token')->getStatusCode());
    }

    /**
     * The screen has to be able to say *what* is suspended and *where to pay*,
     * and both are the server's answers rather than anything derived.
     */
    public function testTheSubscriptionSaysSinceWhenAndWhichInvoiceToPay(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        $body = $this->decode($this->get('/api/v1/subscription', 'ada-token'));
        $current = $body['subscription'] ?? null;

        self::assertIsArray($current);
        self::assertSame('PAST_DUE', $current['status'] ?? null);
        self::assertIsString($current['past_due_since'] ?? null);
        self::assertSame($invoice, $current['past_due_invoice_id'] ?? null);
    }

    /**
     * And the person covered by it is still told what covers them.
     *
     * `coveringPerson` admits PAST_DUE where the coverage deciding what work
     * is reachable refuses it (ADR-060), and that asymmetry is the point: the
     * holder's colleague finds the workshop shut, and this screen is the only
     * thing that can say why. Answering "nothing covers you" precisely then
     * would send them to buy what they already sit on.
     */
    public function testACoveredColleagueIsStillToldWhileTheHolderIsInArrears(): void
    {
        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        $coverage = $this->decode($this->get('/api/v1/subscription', 'ada-token'))['coverage'] ?? null;

        self::assertIsArray($coverage);
        self::assertSame('PAST_DUE', $coverage['status'] ?? null);
    }

    /**
     * Arrears is not an exit, so it does not free the scope.
     *
     * The two partial unique indexes read `status = 'ACTIVE'` before today, so a
     * fourth status silently moved a suspended subscription out from under them
     * — and a customer owing for March could have taken out a second
     * subscription in April and left the first unpaid for ever.
     */
    public function testASuspendedSubscriptionStillOccupiesItsScope(): void
    {
        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        try {
            $subscriptions->subscribe($this->tenant, $this->product, $this->offer, $this->owner);
            self::fail('a suspended subscription must still hold its scope');
        } catch (ConflictException $refused) {
            self::assertSame('ALREADY_SUBSCRIBED', $refused->errorCode());
        }
    }

    /**
     * Paying is what lifts it, and it lifts through the invoice rather than
     * through the payment: §25 lets an invoice reach PAID by a card's webhook or
     * by an operator reconciling a transfer, and a gate hung off the first alone
     * would leave every transfer-paying customer locked out.
     */
    public function testPayingTheInvoiceReopensTheWorkshop(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        self::assertSame(403, $this->projects('ada-token')->getStatusCode());

        $this->settle($invoice);

        self::assertSame('ACTIVE', $this->statusOf($subscription));
        self::assertNull($this->connection->fetchOne(
            'SELECT past_due_since FROM subscriptions WHERE id = :id',
            ['id' => $subscription],
        ));
        self::assertSame(200, $this->projects('ada-token')->getStatusCode());

        // Both ends of the suspension are in the history, because the column
        // only ever says where things stand now.
        self::assertSame(
            1,
            $this->rowsMatching("SELECT count(*) FROM subscription_events WHERE type = 'ARREARS_DECLARED'"),
        );
        self::assertSame(
            1,
            $this->rowsMatching("SELECT count(*) FROM subscription_events WHERE type = 'ARREARS_CLEARED'"),
        );
    }

    /**
     * Another invoice being paid is not this one being paid.
     *
     * A suspension keyed on the subscription alone would be lifted by any money
     * arriving, and a €0 correction would undo a debt the customer never paid.
     */
    public function testPayingADifferentInvoiceLiftsNothing(): void
    {
        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        $other = $this->overdueInvoice($subscription, days: 0, number: '2026-000099');
        $this->settle($other);

        self::assertSame('PAST_DUE', $this->statusOf($subscription));
    }

    // --- 2. the refusal is its own -------------------------------------------

    /**
     * The claim the whole of §5.1 turns on, and the one a sabotage would reach
     * first: told `SUBSCRIPTION_REQUIRED`, the holder goes and asks a colleague
     * for a place they already hold, and the invoice stays unpaid while they
     * wait.
     *
     * Both refusals are asserted in one test on purpose. Either alone passes
     * with the two codes collapsed into one.
     */
    public function testTheArrearsRefusalIsNotTheOneAColleagueAnswers(): void
    {
        $subscription = $this->subscribe();

        // The colleague is on no subscription: this is the fifth refusal, and
        // it must stay exactly as it was.
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($this->projects('bo-token'))['code'] ?? null);

        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        // The owner *is* on one, and it is suspended: a different refusal,
        // answered by a card and not by a colleague.
        $refused = $this->errorOf($this->projects('ada-token'));

        self::assertSame('SUBSCRIPTION_PAST_DUE', $refused['code'] ?? null);
        self::assertNotSame('SUBSCRIPTION_REQUIRED', $refused['code'] ?? null);
        self::assertNotSame('ENTITLEMENT_REQUIRED', $refused['code'] ?? null);

        // And the colleague still gets theirs: a suspension does not rewrite
        // what somebody on no subscription is told.
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($this->projects('bo-token'))['code'] ?? null);
    }

    /**
     * A refusal is a poor place to publish a price, so the debt does not travel
     * with it — the remedy is read from `GET /subscription`, which coverage does
     * not gate.
     */
    public function testTheRefusalPublishesNothingAboutTheDebt(): void
    {
        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 2);
        $this->collect();

        $refused = $this->errorOf($this->projects('ada-token'));

        self::assertSame([], $refused['details'] ?? []);
    }

    // --- 3. a chase is an attempt and a notification, from the queue ----------

    /**
     * Each chase is a payment in its own right — "a new attempt, never the same
     * one revived" — and a notification, never a message.
     */
    public function testAChaseIsAFreshAttemptAndAOneWayNotice(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 1);

        $pass = $this->collect();

        self::assertSame(1, $pass['suspended'] ?? null);
        self::assertSame(1, $pass['chased'] ?? null);

        // A new payment row against the invoice, which is what an attempt is.
        self::assertSame(1, $this->attemptsOn($invoice));

        // A notification, with legal effect because a formal demand is kept as
        // sent, and deliveries written **up front** — one per candidate channel,
        // before anything is sent, which is what makes a suppression
        // recordable.
        $notice = $this->newestNotice();

        self::assertSame(CollectOverdueInvoices::NOTICE_TYPE, $notice['type'] ?? null);
        self::assertSame($this->owner, $notice['recipient_user_id'] ?? null);
        self::assertTrue($notice['legal_effect'] ?? null);
        self::assertSame(
            ['EMAIL', 'SCREEN'],
            $this->connection->fetchFirstColumn(
                'SELECT channel FROM notification_deliveries WHERE notification_id = :id ORDER BY channel',
                ['id' => $notice['id']],
            ),
        );

        // And not a message (§12.3): a conversation has participants, an order
        // and a reply, and a chase has none of the three.
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM messages'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM conversations'));
    }

    /**
     * The demand has legal effect, so the rendered body is kept — for the reason
     * an invoice keeps its snapshot. "Did we tell them, and what did we say?" is
     * the question the whole channel exists to answer.
     */
    public function testTheRenderedDemandIsKeptAsItWasSent(): void
    {
        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 3, number: '2026-000042');
        $this->collect();

        $this->dispatch();

        $body = $this->connection->fetchOne(
            <<<'SQL'
                SELECT d.rendered_body
                  FROM notification_deliveries d
                  JOIN notifications n ON n.id = d.notification_id
                 WHERE n.type = :type AND d.channel = 'EMAIL'
                SQL,
            ['type' => CollectOverdueInvoices::NOTICE_TYPE],
        );

        self::assertIsString($body);
        self::assertStringContainsString('2026-000042', $body);
    }

    /**
     * Nothing chases inside an HTTP request (§27.1, §5.2).
     *
     * Reading the very screen the debt is about writes no notice and starts no
     * payment: what the queue does, the queue does.
     */
    public function testReadingTheScreenChasesNothing(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 7);

        self::assertSame(200, $this->get('/api/v1/subscription', 'ada-token')->getStatusCode());
        self::assertSame(200, $this->get('/api/v1/billing/invoices', 'ada-token')->getStatusCode());
        self::assertSame(200, $this->projects('ada-token')->getStatusCode());

        self::assertSame(0, $this->noticesRaised());
        self::assertSame(0, $this->attemptsOn($invoice));
        self::assertSame('ACTIVE', $this->statusOf($subscription));
    }

    /**
     * One notice and one attempt per step, however many passes run.
     *
     * A cron pass every night through a seven-day window must write three
     * notices, not seven — and must not authorize a payment every night, which
     * is why the attempt is behind the same claim as the notice.
     */
    public function testANightlyPassChasesOncePerStepAndNotOncePerNight(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 1);

        $this->collect();
        $second = $this->collect();
        $this->collect();

        self::assertSame(0, $second['chased'] ?? null);
        self::assertSame(1, $second['already_chased'] ?? null);
        self::assertSame(1, $this->noticesRaised());
        self::assertSame(1, $this->attemptsOn($invoice));
    }

    /**
     * One debt, several people who can settle it: every one of them is told,
     * and the money is asked for **once**.
     *
     * `overdue()` answers one row per recipient — that is how a chase reaches
     * somebody who can act on it — so an organisation with two administrators is
     * two rows about one invoice. Attempting per row would put two payment
     * intents against one document and the customer would find both.
     */
    public function testEveryAdministratorIsToldAndTheMoneyIsAskedForOnce(): void
    {
        $subscription = $this->subscribe();
        // A second administrator, in the database this time: the recipient of a
        // chase is resolved in SQL off the subscription's own tenant.
        $this->administratorInTheDatabase($this->colleague);
        $invoice = $this->overdueInvoice($subscription, days: 2);

        $pass = $this->collect();

        self::assertSame(2, $pass['chased'] ?? null, 'Ada owns it, Bo administers');
        self::assertSame(2, $this->noticesRaised());
        self::assertSame(1, $this->attemptsOn($invoice), 'one debt, one attempt');
    }

    /**
     * A debt with nobody to write to is counted, not skipped — a chase that
     * reaches nobody must not read as nothing to collect. The suspension still
     * stands: the money is owed whether or not there is an address.
     */
    public function testADebtWithNobodyToTellIsCountedAndStillSuspends(): void
    {
        $subscription = $this->subscribe();
        $this->connection->executeStatement(
            'UPDATE subscriptions SET owner_user_id = NULL WHERE id = :id',
            ['id' => $subscription],
        );
        $this->connection->executeStatement('DELETE FROM tenant_member_roles');
        $this->overdueInvoice($subscription, days: 2);

        $pass = $this->collect();

        self::assertSame(1, $pass['unaddressed'] ?? null);
        self::assertSame(1, $pass['suspended'] ?? null);
        self::assertSame(0, $this->noticesRaised());
    }

    // --- 4. the schedule is the product's ------------------------------------

    /**
     * Inside the grace the product chose, nothing is written at all.
     *
     * The first chase *is* the grace (§5.2): every invoice here is payable on
     * receipt, so declaring arrears at the due second would suspend a customer
     * in the middle of paying — an upgrade's proration invoice is issued and
     * settled seconds apart.
     */
    public function testNothingHappensInsideTheGrace(): void
    {
        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 0);

        $pass = $this->collect();

        self::assertSame(1, $pass['within_grace'] ?? null);
        self::assertSame(0, $pass['suspended'] ?? null);
        self::assertSame('ACTIVE', $this->statusOf($subscription));
        self::assertSame(0, $this->attemptsOn($invoice));
        self::assertSame(200, $this->projects('ada-token')->getStatusCode());
    }

    /**
     * **The schedule comes from the product, not from a constant.** Configure a
     * product to wait five days and two days overdue is inside its grace, where
     * the default's J+1 would already have suspended and chased.
     *
     * This is the sabotage target: read the schedule from
     * `DunningSchedule::DEFAULT_RETRIES` instead of from the configuration and
     * this test fails, while every other one here passes.
     */
    public function testTheProductsOwnScheduleDecidesAndNotTheDefault(): void
    {
        $this->configureSchedule([5, 20]);

        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 2);

        self::assertSame(1, $this->collect()['within_grace'] ?? null, 'two days is inside a five-day grace');
        self::assertSame('ACTIVE', $this->statusOf($subscription));

        // …and on the day it chose, it chases. Once, on step 1.
        $this->connection->executeStatement(
            "UPDATE invoices SET issued_at = now() - interval '6 days' WHERE id = :id",
            ['id' => $invoice],
        );

        self::assertSame(1, $this->collect()['chased'] ?? null);
        self::assertSame('PAST_DUE', $this->statusOf($subscription));
        self::assertSame(1, $this->noticesRaised());
    }

    /**
     * Past the last step it stops, and stopping needs no state: the schedule
     * keeps answering the last step, whose notice already exists, and the index
     * refuses a second.
     */
    public function testPastTheLastStepItStops(): void
    {
        $this->configureSchedule([1, 3]);

        $subscription = $this->subscribe();
        $invoice = $this->overdueInvoice($subscription, days: 1);

        $this->collect();
        $this->connection->executeStatement(
            "UPDATE invoices SET issued_at = now() - interval '4 days' WHERE id = :id",
            ['id' => $invoice],
        );
        $this->collect();

        self::assertSame(2, $this->noticesRaised(), 'two steps, two notices');

        // A month later, and still two: there is no third step to reach.
        $this->connection->executeStatement(
            "UPDATE invoices SET issued_at = now() - interval '40 days' WHERE id = :id",
            ['id' => $invoice],
        );
        $this->collect();

        self::assertSame(2, $this->noticesRaised());
        self::assertSame(2, $this->attemptsOn($invoice));
        // And it is never cancelled: ending a contract is a decision, with a
        // rule and an effective date, and a collection pass is not where one
        // gets taken.
        self::assertSame('PAST_DUE', $this->statusOf($subscription));
    }

    /**
     * A schedule nobody can read is not a decision an operator made, so it is
     * treated exactly as none — the default, because failing closed here would
     * mean never chasing an unpaid invoice.
     */
    public function testAnUnusableScheduleReadsAsTheDefault(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'dunning', CAST('{"retries":[7,3,1]}' AS jsonb))
                SQL,
            ['product' => $this->product],
        );

        $subscription = $this->subscribe();
        $this->overdueInvoice($subscription, days: 1);

        self::assertSame(1, $this->collect()['chased'] ?? null, 'out of order, so the default decides');
        self::assertSame(DunningSchedule::DEFAULT_RETRIES, DunningSchedule::theDefault()->retries);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * One pass of the collection job.
     *
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $handler = $this->container()->get(CollectOverdueInvoices::class);
        self::assertInstanceOf(CollectOverdueInvoices::class, $handler);

        return $handler->handle($this->job(CollectOverdueInvoices::TYPE));
    }

    /** One pass of the channel that actually sends. */
    private function dispatch(): void
    {
        $handler = $this->container()->get(DispatchNotifications::class);
        self::assertInstanceOf(DispatchNotifications::class, $handler);

        $handler->handle($this->job(DispatchNotifications::TYPE));
    }

    private function job(string $type): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            $type,
            'QUEUED',
            null,
            null,
            [],
            null,
            null,
            0,
            0,
            5,
            $now,
            null,
            null,
            null,
            null,
            $now,
        );
    }

    /** The organisation's own subscription, started the way the platform does. */
    private function subscribe(): string
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        return $subscriptions->subscribe($this->tenant, $this->product, $this->offer, $this->owner)->id;
    }

    /**
     * An invoice raised against the subscription, issued this many days ago and
     * still unpaid. Written directly, because what is under test is what the
     * collection pass does with a debt rather than how a debt is raised.
     */
    private function overdueInvoice(string $subscription, int $days, string $number = '2026-000001'): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, subscription_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units, issued_at)
                VALUES (:tenant, :product, :subscription, :number, 'ISSUED', 'EUR',
                        2900, 580, 3480, now() - make_interval(days => :days))
                RETURNING id
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'subscription' => $subscription,
                'number' => $number,
                'days' => $days,
            ],
        );
    }

    /**
     * The invoice reaches PAID, through the repository the webhook and the
     * operator's reconciliation both go through.
     */
    private function settle(string $invoiceId): void
    {
        $invoices = $this->container()->get(InvoiceRepository::class);
        self::assertInstanceOf(InvoiceRepository::class, $invoices);

        $invoice = $invoices->find($this->tenant, $this->product, $invoiceId);
        self::assertNotNull($invoice);

        $paid = $this->container()->get(\App\Billing\Domain\InvoicePaid::class);
        self::assertInstanceOf(\App\Billing\Domain\InvoicePaid::class, $paid);

        $invoices->settle($invoice, $paid, null);

        $status = $this->connection->fetchOne(
            'SELECT status FROM invoices WHERE id = :id',
            ['id' => $invoiceId],
        );

        self::assertSame(InvoiceStatus::PAID, $status);
    }

    /** @param list<int> $retries */
    private function configureSchedule(array $retries): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'dunning', CAST(:value AS jsonb))
                SQL,
            [
                'product' => $this->product,
                'value' => json_encode(DunningSchedule::ofDays($retries)->asConfiguration(), JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function statusOf(string $subscriptionId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM subscriptions WHERE id = :id',
            ['id' => $subscriptionId],
        );

        self::assertIsString($status);

        return $status;
    }

    private function attemptsOn(string $invoiceId): int
    {
        return $this->counted(
            'SELECT count(*) FROM payments WHERE invoice_id = :invoice',
            ['invoice' => $invoiceId],
        );
    }

    private function noticesRaised(): int
    {
        return $this->counted(
            'SELECT count(*) FROM notifications WHERE type = :type',
            ['type' => CollectOverdueInvoices::NOTICE_TYPE],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function newestNotice(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, type, recipient_user_id, legal_effect, payload FROM notifications'
            . ' ORDER BY created_at DESC LIMIT 1',
        );

        self::assertIsArray($row);

        return $row;
    }

    private function projects(string $token): ResponseInterface
    {
        return $this->get('/api/v1/projects', $token);
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, [
            'Authorization' => 'Bearer ' . $token,
            'X-Product' => 'atlas',
        ]);
    }

    private function rowsMatching(string $sql): int
    {
        return $this->counted($sql, []);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function counted(string $sql, array $parameters): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }

    /**
     * A membership written to the database, because the chase resolves its
     * recipients in SQL off the subscription's own tenant — the membership port
     * is shaped "every tenant this *user* belongs to" on purpose, and nothing in
     * that query comes from a client.
     */
    private function administratorInTheDatabase(string $userId): void
    {
        TestDatabase::assignProduct($this->connection, $this->tenant, $this->product);

        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $this->tenant, 'user' => $userId, 'product' => $this->product],
        );

        $this->connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)'
            . " SELECT :tenant, :user, :product, id FROM roles WHERE code = 'TENANT_ADMIN'",
            ['tenant' => $this->tenant, 'user' => $userId, 'product' => $this->product],
        );
    }

    private function person(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
    }

    private function seedCatalogue(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'project_schema_versions', CAST('{"supported":[1]}' AS jsonb))
                SQL,
            ['product' => $this->product],
        );

        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $feature = 'INSERT INTO features (code, name, kind, unit) VALUES (:code, :code, :kind, :unit) RETURNING id';
        $projects = $this->id($feature, ['code' => 'max_projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $users = $this->id($feature, ['code' => 'users', 'kind' => 'QUOTA', 'unit' => 'users']);

        $this->offer = $this->id(
            "INSERT INTO offers (product_id, plan_id, code, name) VALUES (:product, :plan, 'pro', 'Pro') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $this->offer],
        );

        foreach ([[$projects, 50], [$users, 3]] as [$featureId, $limit]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    VALUES (:version, :feature, :limit)
                    SQL,
                ['version' => $version, 'feature' => $featureId, 'limit' => $limit],
            );
        }

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );
    }
}
