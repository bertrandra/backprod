<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Job\Domain\Job;
use App\Job\Service\SendRenewalNotices;
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

        self::assertSame(0, $this->run()['raised'] ?? null);
        self::assertSame(0, $this->noticesRaised());
    }

    /**
     * Past the term end there is no *prior* notice left to give. Sending one
     * anyway would be a claim that the deadline was met.
     */
    public function testNothingIsSentOnceTheTermHasPassed(): void
    {
        $this->tenantSubscription(endsInDays: -1, noticeDays: 30);

        self::assertSame(0, $this->run()['raised'] ?? null);
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

        self::assertSame(0, $this->run()['raised'] ?? null);
    }

    public function testASubscriptionThatDoesNotRenewItselfNeedsNoNotice(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30, renewal: 'ENDS_AT_TERM');

        self::assertSame(0, $this->run()['raised'] ?? null);
    }

    /**
     * A tenant with nobody who can act on the notice is an unmet obligation,
     * not an empty queue. It is counted so it cannot read as a quiet success
     * — which is the exact shape of the failure R11 warns about.
     */
    public function testATenantWithNoAdministratorIsCountedRatherThanSkipped(): void
    {
        $this->connection->executeStatement('DELETE FROM tenant_member_roles');
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);

        $pass = $this->run();

        self::assertSame(0, $pass['raised'] ?? null);
        self::assertSame(1, $pass['unaddressed'] ?? null);
    }

    // --- and what must ---------------------------------------------------------

    public function testTheAdministratorsAreToldBeforeATenantSubscriptionRenews(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);

        self::assertSame(1, $this->run()['raised'] ?? null);

        $notice = $this->newestNotice();
        self::assertSame('subscription.renewal_notice', $notice['type'] ?? null);
        self::assertSame($this->admin, $notice['recipient_user_id'] ?? null);
        // Kept as it was sent: "what did we say?" is half the question.
        // Counted rather than read back, because a driver's idea of a
        // PostgreSQL boolean is not something this test should depend on.
        self::assertSame(1, $this->rowsMatching(
            'SELECT count(*) FROM notifications WHERE legal_effect = true',
        ));
    }

    /**
     * A member without the role cannot cancel the subscription, so telling
     * them is not telling anybody who can act.
     */
    public function testAMemberWhoIsNotAnAdministratorIsNotTold(): void
    {
        $this->tenantSubscription(endsInDays: 10, noticeDays: 30);
        $this->run();

        $told = $this->connection->fetchOne(
            'SELECT count(*) FROM notifications WHERE recipient_user_id = :user',
            ['user' => $this->plainMember],
        );

        self::assertSame(0, (int) $told);
    }

    public function testASeatHoldersNoticeGoesToTheSeatHolder(): void
    {
        $this->seatSubscription(endsInDays: 7, noticeDays: 30);

        self::assertSame(1, $this->run()['raised'] ?? null);
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

        $first = $this->run();
        $second = $this->run();
        $third = $this->run();

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
        $this->run();

        $this->connection->executeStatement(
            "UPDATE subscriptions SET term_ends_at = now() + interval '20 days' WHERE id = :id",
            ['id' => $id],
        );

        self::assertSame(1, $this->run()['raised'] ?? null);
        self::assertSame(2, $this->noticesRaised());
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function run(): array
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
        return $this->subscription('TENANT', null, $endsInDays, $noticeDays, $renewal);
    }

    private function seatSubscription(int $endsInDays, int $noticeDays): string
    {
        return $this->subscription('USER', $this->seatHolder, $endsInDays, $noticeDays);
    }

    private function subscription(
        string $kind,
        ?string $subscriberUserId,
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
                    (tenant_id, product_id, offer_version_id, status, subscriber_kind,
                     subscriber_user_id, started_at, current_period_start,
                     term_months, term_ends_at, notice_days, renewal)
                VALUES (:tenant, :product, :version, 'ACTIVE', :kind,
                        CAST(:subscriber AS uuid),
                        now() - interval '400 days', now() - interval '400 days',
                        12, now() + make_interval(days => :endsIn), :noticeDays, :renewal)
                RETURNING id
                SQL,
            [
                'tenant' => $this->tenant,
                'product' => $this->product,
                'version' => $this->offerVersion,
                'kind' => $kind,
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
