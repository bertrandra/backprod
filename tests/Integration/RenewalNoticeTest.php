<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Job\Domain\Job;
use App\Job\Service\SendRenewalNotices;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The prior notice a tacit renewal needs (§13.1, R11).
 *
 * The obligation is a deadline, so the tests are about *when* rather than
 * about wording: a notice sent late is a notice not sent, and one sent for a
 * subscription that is already ending is worse than silence.
 *
 * Refusals first (§37.4). Everything here that must **not** produce a notice
 * comes before the one case that must, because a job that notified everybody
 * would pass a test that only checked the happy path.
 *
 * Against the real database throughout: the window is `notice_days` before
 * `term_ends_at` evaluated by PostgreSQL, and once-per-term is a partial
 * unique index. A double would be a second implementation of both.
 */
#[CoversNothing]
final class RenewalNoticeTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $offerVersion = '';
    private string $admin = '';
    private string $plainMember = '';
    private string $seatHolder = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->admin = $this->user('sub-ada', 'ada@acme.test');
        $this->plainMember = $this->user('sub-pat', 'pat@acme.test');
        $this->seatHolder = $this->user('sub-sam', 'sam@acme.test');

        $this->member($this->admin, 'TENANT_ADMIN');
        $this->member($this->plainMember, 'USER');

        $this->seedCatalogue();
    }

    // --- what must not produce a notice ---------------------------------------

    public function testASubscriptionOutsideItsNoticeWindowIsLeftAlone(): void
    {
        $this->tenantSubscription(endsInDays: 90, noticeDays: 30);

        self::assertSame(0, $this->sweep()['raised'] ?? null);
        self::assertSame(0, $this->noticesRaised());
    }

    /**
     * Past the term end there is no *prior* notice left to give. Sending one
     * anyway would be a claim that the deadline was met.
     */
    public function testNothingIsSentOnceTheTermHasPassed(): void
    {
        $this->tenantSubscription(endsInDays: -1, noticeDays: 30);

        self::assertSame(0, $this->sweep()['raised'] ?? null);
    }

    /**
     * The customer has already said no. There is no tacit renewal to warn
     * about, and warning them would be worse than saying nothing.
     */
    public function testASubscriptionAlreadySetToEndIsNotWarnedAboutRenewing(): void
    {
        $id = $this->tenantSubscription(endsInDays: 10, noticeDays: 30);
        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true,'
            . " cancel_effective_at = now() + interval '10 days' WHERE id = :id",
            ['id' => $id],
        );

        self::assertSame(0, $this->sweep()['raised'] ?? null);
    }

    public function testASubscriptionThatDoesNotRenewItselfNeedsNoNotice(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30, renewal: 'ENDS_AT_TERM');

        self::assertSame(0, $this->sweep()['raised'] ?? null);
    }

    /**
     * A subscription always has somebody to tell, since 2026-10-01.
     *
     * This read "a tenant with no administrator is counted rather than
     * skipped": a subscription whose contracting party was the *organisation*
     * had nobody to notify unless somebody held `TENANT_ADMIN`, and an
     * obligation with no recipient had to be counted so it could not read as a
     * quiet success (R11).
     *
     * `subscriber_user_id` is NOT NULL now, so the recipient is on the row and
     * `unaddressed` is structurally zero. The counter stays in the pass — a
     * nightly job that could not say "I had nobody to tell" is the shape R11
     * warns about, and the day a notice is owed to somebody other than the
     * holder it will be needed again — but a reader should know it cannot fire
     * today, and this is what says so.
     */
    public function testEverySubscriptionHasSomebodyToTell(): void
    {
        $this->connection->executeStatement('DELETE FROM tenant_member_roles');
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);

        $pass = $this->sweep();

        self::assertSame(1, $pass['raised'] ?? null, 'the holder is on the row, roles or no roles');
        self::assertSame(0, $pass['unaddressed'] ?? null);
    }

    // --- and what must ---------------------------------------------------------

    public function testTheHolderIsToldBeforeTheirSubscriptionRenews(): void
    {
        // It was the organisation's administrators who were told, because the
        // organisation was the contracting party. Since 2026-10-01 there is
        // one party and it is a person: a tacit renewal is notified to whoever
        // agreed to it, which is the only thing §13.1 ever asked for.
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);

        self::assertSame(1, $this->sweep()['raised'] ?? null);

        $notice = $this->newestNotice();
        self::assertSame('subscription.renewal_notice', $notice['type'] ?? null);
        self::assertSame($this->seatHolder, $notice['recipient_user_id'] ?? null);
        // Kept as it was sent: "what did we say?" is half the question.
        // Counted rather than read back, because a driver's idea of a
        // PostgreSQL boolean is not something this test should depend on.
        self::assertSame(1, $this->rowsMatching(
            'SELECT count(*) FROM notifications WHERE legal_effect = true',
        ));
    }

    /**
     * A colleague cannot cancel somebody else's subscription, so telling them
     * is not telling anybody who can act — and it would also be telling them
     * what a colleague pays and when.
     *
     * It read "a member without the role", about an administrator's role over
     * the organisation's subscription. With one party the test is the same
     * sentence about a different relationship.
     */
    public function testAColleagueWhoDoesNotHoldItIsNotTold(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);
        $this->sweep();

        $told = $this->connection->fetchOne(
            'SELECT count(*) FROM notifications WHERE recipient_user_id = :user',
            ['user' => $this->plainMember],
        );

        self::assertIsNumeric($told);
        self::assertSame(0, (int) $told);
    }

    public function testASeatHoldersNoticeGoesToTheSeatHolder(): void
    {
        $this->seatSubscription(endsInDays: 7, noticeDays: 30);

        self::assertSame(1, $this->sweep()['raised'] ?? null);
        self::assertSame($this->seatHolder, $this->newestNotice()['recipient_user_id'] ?? null);
    }

    /**
     * The job runs nightly through a window that is thirty days wide. Once
     * per term is the partial unique index, not a check each pass would
     * separately pass.
     */
    public function testRunningEveryNightThroughTheWindowSendsOneNotice(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);

        $first = $this->sweep();
        $second = $this->sweep();
        $third = $this->sweep();

        self::assertSame(1, $first['raised'] ?? null);
        self::assertSame(0, $second['raised'] ?? null);
        self::assertSame(1, $second['already_noticed'] ?? null);
        self::assertSame(0, $third['raised'] ?? null);
        self::assertSame(1, $this->noticesRaised());
    }

    /**
     * The term is part of the key, so a subscription renewing year after year
     * is noticed once a year. Keyed on the subscription alone, every renewal
     * after the first would go unannounced.
     */
    public function testTheNextTermGetsItsOwnNotice(): void
    {
        $id = $this->tenantSubscription(endsInDays: 10, noticeDays: 30);
        $this->sweep();

        $this->connection->executeStatement(
            "UPDATE subscriptions SET term_ends_at = now() + interval '20 days' WHERE id = :id",
            ['id' => $id],
        );

        self::assertSame(1, $this->sweep()['raised'] ?? null);
        self::assertSame(2, $this->noticesRaised());
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * One pass of the job.
     *
     * Not `run()`: PHPUnit's own `TestCase::run()` is final, and a helper by
     * that name is a name collision rather than a helper.
     *
     * @return array<string, mixed>
     */
    private function sweep(): array
    {
        $handler = $this->container()->get(SendRenewalNotices::class);
        self::assertInstanceOf(SendRenewalNotices::class, $handler);

        return $handler->handle($this->job());
    }

    private function job(): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            SendRenewalNotices::TYPE,
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

    private function tenantSubscription(int $endsInDays, int $noticeDays, string $renewal = 'AUTO_RENEW'): string
    {
        // It said 'TENANT' and named nobody until 2026-10-01. The case it
        // serves is about the renewal clock rather than about who contracted,
        // so it keeps its meaning with a holder put on it.
        return $this->subscription($this->seatHolder, $endsInDays, $noticeDays, $renewal);
    }

    private function seatSubscription(int $endsInDays, int $noticeDays): string
    {
        return $this->subscription($this->seatHolder, $endsInDays, $noticeDays);
    }

    private function subscription(
        string $subscriberUserId,
        int $endsInDays,
        int $noticeDays,
        string $renewal = 'AUTO_RENEW',
    ): string {
        // started_at is pushed well back so a term ending in the past is still
        // a term that began before it — subscriptions_term_after_start refuses
        // anything else, which is the constraint doing its job.
        return $this->id(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, status,
                     subscriber_user_id, started_at, current_period_start,
                     term_months, term_ends_at, notice_days, renewal)
                VALUES (:tenant, :product, :version, 'ACTIVE',
                        CAST(:subscriber AS uuid),
                        now() - interval '400 days', now() - interval '400 days',
                        12, now() + make_interval(days => :endsIn), :noticeDays, :renewal)
                RETURNING id
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'version' => $this->offerVersion,
                'subscriber' => $subscriberUserId,
                'endsIn' => $endsInDays,
                'noticeDays' => $noticeDays,
                'renewal' => $renewal,
            ],
        );
    }

    private function seedCatalogue(): void
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'pro', 'Atlas Pro') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $this->offerVersion = $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 10000, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $offer],
        );
    }

    private function user(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
    }

    private function member(string $userId, string $role): void
    {
        TestDatabase::assignProduct($this->connection, $this->tenant, $this->product);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id)'
            . ' VALUES (:tenant, :user, :product)',
            ['tenant' => $this->tenant, 'user' => $userId, 'product' => $this->product],
        );

        $this->connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)'
            . ' SELECT :tenant, :user, :product, id FROM roles WHERE code = :role',
            [
                'tenant' => $this->tenant,
                'user' => $userId,
                'product' => $this->product,
                'role' => $role,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function newestNotice(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT type, recipient_user_id FROM notifications ORDER BY created_at DESC LIMIT 1',
        );

        self::assertIsArray($row);

        return $row;
    }

    private function noticesRaised(): int
    {
        return $this->rowsMatching(
            "SELECT count(*) FROM notifications WHERE type = 'subscription.renewal_notice'",
        );
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }
}
