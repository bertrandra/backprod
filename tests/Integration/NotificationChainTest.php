<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Job\Domain\Job;
use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\NotificationRepository;
use App\Notification\Service\DispatchNotifications;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Notifications through the real pipeline, the real database and the real
 * dispatcher.
 *
 * A double would defeat most of these: exactly-once is a unique index,
 * deduplication is a partial unique index, and "security cannot be muted" is
 * a CHECK constraint — all three are what an in-memory repository would get
 * right by accident.
 */
#[CoversNothing]
final class NotificationChainTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id",
        );
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['notifications.read', 'notifications.manage'],
                ),
            ]),
        ]);
    }

    // --- what must not be sent -------------------------------------------

    public function testSmsWithoutConsentIsSuppressedRatherThanSent(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::SMS, Channel::SCREEN]);

        $this->dispatch();

        // Fail closed, and recorded: "we did not send it" and "we never
        // tried" are different answers to "did we tell them?".
        self::assertSame(
            ['SUPPRESSED', 'NO_CONSENT'],
            $this->deliveryState(Channel::SMS),
        );
    }

    public function testAMutedChannelIsSuppressedAndTheOthersStillGo(): void
    {
        $this->setPreference(Category::BILLING, Channel::EMAIL, false);
        $this->raise('payment.failed', Category::BILLING, [Channel::EMAIL, Channel::SCREEN]);

        $this->dispatch();

        self::assertSame(['SUPPRESSED', 'OPTED_OUT'], $this->deliveryState(Channel::EMAIL));
        // One failing channel must not take the others down.
        self::assertSame(['SENT', null], $this->deliveryState(Channel::SCREEN));
    }

    public function testRevokingConsentStopsFurtherSending(): void
    {
        $granted = $this->decode($this->grantConsent(Channel::SMS))['consent'] ?? null;
        self::assertIsArray($granted);

        $consentId = $granted['id'] ?? null;
        self::assertIsString($consentId);

        $response = $this->request(
            'DELETE',
            '/api/v1/notifications/consents/' . $consentId,
            $this->headers(),
        );
        self::assertSame(200, $response->getStatusCode());

        $this->raise('payment.failed', Category::BILLING, [Channel::SMS]);
        $this->dispatch();

        self::assertSame(['SUPPRESSED', 'NO_CONSENT'], $this->deliveryState(Channel::SMS));
    }

    public function testSecurityNotificationsCannotBeSwitchedOff(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/notifications/preferences',
            $this->headers(),
            $this->json(['category' => Category::SECURITY, 'channel' => Channel::EMAIL, 'enabled' => false]),
        );

        // A notice the recipient can mute is one an attacker can mute
        // (non-negotiable #24).
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    public function testASecurityNoticeGoesOutRegardless(): void
    {
        // Even with a muted *billing* preference sitting alongside it.
        $this->setPreference(Category::BILLING, Channel::EMAIL, false);
        $this->raise('security.sign_in', Category::SECURITY, [Channel::EMAIL]);

        $this->dispatch();

        self::assertSame(['SENT', null], $this->deliveryState(Channel::EMAIL));
    }

    // --- exactly-once and deduplication -----------------------------------

    public function testDispatchingTwiceSendsOnce(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::EMAIL]);

        $first = $this->dispatch();
        $second = $this->dispatch();

        self::assertSame(1, $first['sent'] ?? null);
        // The easy half: the first pass finished, so the row is SENT and the
        // second pass finds nothing waiting. The hard half — a second pass
        // arriving while the first is still sending — is the test below.
        self::assertSame(0, $second['sent'] ?? null);
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM notification_deliveries WHERE status = 'SENT'",
        ));
    }

    /**
     * The case the test above cannot reach.
     *
     * Dispatching twice in sequence sends once because the first pass
     * finished — the row was SENT before the second looked. The duplicate
     * R12 names happens when the second pass starts while the first is still
     * sending, which on a cron-polled queue is the ordinary case rather than
     * an error. So this claims without finishing, exactly as a runner
     * mid-pass has, and then lets a whole dispatch run against it.
     */
    public function testASecondPassCannotTakeADeliveryTheFirstIsStillSending(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::EMAIL]);

        $inFlight = $this->repository()->claimPending(50, 900, 3);
        self::assertCount(1, $inFlight);

        $second = $this->dispatch();

        self::assertSame(0, $second['sent'] ?? null);
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM notification_deliveries WHERE status = 'SENT'",
        ));
        self::assertSame(['SENDING', null], $this->deliveryState(Channel::EMAIL));
        self::assertSame(1, $this->attemptsOn(Channel::EMAIL));
    }

    /**
     * The other half of that trade: holding a claim must not lose the notice
     * when the holder dies. A lapse is a fact about the clock, so the lease
     * expiring is not a verdict on the delivery.
     */
    public function testALeaseThatLapsedIsClaimedAgain(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::EMAIL]);
        $this->repository()->claimPending(50, 900, 3);
        $this->lapseTheLease();

        $recovered = $this->dispatch();

        self::assertSame(1, $recovered['sent'] ?? null);
        self::assertSame(['SENT', null], $this->deliveryState(Channel::EMAIL));
        self::assertSame(2, $this->attemptsOn(Channel::EMAIL));
    }

    /**
     * And a bound on that, or a message that kills whoever picks it up would
     * be retried for ever and nothing behind it would go out.
     */
    public function testADeliveryThatSpentItsAttemptsIsGivenUpOnRatherThanRetried(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::EMAIL]);
        $this->repository()->claimPending(50, 900, 3);
        $this->connection->executeStatement(
            "UPDATE notification_deliveries SET attempts = 3, leased_until = now() - interval '1 minute'",
        );

        $swept = $this->dispatch();

        self::assertSame(1, $swept['abandoned'] ?? null);
        self::assertSame(0, $swept['sent'] ?? null);
        self::assertSame(['FAILED', null], $this->deliveryState(Channel::EMAIL));
        self::assertSame('LEASE_EXPIRED', $this->connection->fetchOne(
            'SELECT failure_reason FROM notification_deliveries',
        ));
    }

    public function testABurstOfTheSameEventProducesOneNotice(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::SCREEN], 'today');
        $this->raise('payment.failed', Category::BILLING, [Channel::SCREEN], 'today');
        $this->raise('payment.failed', Category::BILLING, [Channel::SCREEN], 'today');

        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM notifications'));
    }

    public function testWithoutADedupKeyNothingIsDeduplicated(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::SCREEN]);
        $this->raise('payment.failed', Category::BILLING, [Channel::SCREEN]);

        // Two genuinely separate failures are two notices.
        self::assertSame(2, $this->rowsMatching('SELECT count(*) FROM notifications'));
    }

    // --- the screen channel ------------------------------------------------

    public function testTheRecipientReadsTheirOwnNotifications(): void
    {
        $this->raise('export.ready', Category::ACCOUNT, [Channel::SCREEN]);

        $body = $this->decode($this->request('GET', '/api/v1/notifications', $this->headers()));

        self::assertSame(1, $body['total'] ?? null);
        self::assertSame(1, $body['unread'] ?? null);

        $notifications = $body['notifications'] ?? null;
        self::assertIsArray($notifications);

        $notification = $notifications[0] ?? null;
        self::assertIsArray($notification);
        self::assertSame('export.ready', $notification['type'] ?? null);
    }

    public function testReadingTwiceKeepsTheFirstTimestamp(): void
    {
        $this->raise('export.ready', Category::ACCOUNT, [Channel::SCREEN]);

        $id = $this->newestId();

        $first = $this->readAt($id);
        $second = $this->readAt($id);

        // "When did they see it?" has one answer, and the first one is true.
        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function testAnotherPersonsNotificationIsNotFound(): void
    {
        $other = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bob', 'bob@example.test') RETURNING id",
        );

        $id = $this->raiseFor($other, 'export.ready', Category::ACCOUNT, [Channel::SCREEN]);

        $response = $this->read($id);

        // The same answer as "does not exist". An id is not an authorisation,
        // and telling the two apart would allow enumeration.
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeliveriesExplainWhatHappened(): void
    {
        $this->raise('payment.failed', Category::BILLING, [Channel::SMS, Channel::SCREEN]);
        $this->dispatch();

        $id = $this->newestId();

        $deliveries = $this->decode(
            $this->request('GET', '/api/v1/notifications/' . $id . '/deliveries', $this->headers()),
        )['deliveries'] ?? null;

        self::assertIsArray($deliveries);
        self::assertCount(2, $deliveries);

        /** @var array<string, string|null> $reasons */
        $reasons = [];

        foreach ($deliveries as $delivery) {
            self::assertIsArray($delivery);

            $channel = $delivery['channel'] ?? null;
            self::assertIsString($channel);

            $reason = $delivery['suppression_reason'] ?? null;
            $reasons[$channel] = is_string($reason) ? $reason : null;
        }

        // This is the endpoint that answers "did you text me about this?"
        // with something better than a shrug.
        self::assertSame('NO_CONSENT', $reasons[Channel::SMS] ?? null);

        // The screen delivery is present *and* unsuppressed. Written as two
        // assertions because "?? 'unset'" cannot express it: ?? fires on a
        // null value as readily as on a missing key, so that form asserts
        // something no run could ever satisfy.
        self::assertArrayHasKey(Channel::SCREEN, $reasons);
        self::assertNull($reasons[Channel::SCREEN]);
    }

    public function testPreferencesComeBackWithDefaultsFilledIn(): void
    {
        $body = $this->decode(
            $this->request('GET', '/api/v1/notifications/preferences', $this->headers()),
        );

        $preferences = $body['preferences'] ?? null;
        self::assertIsArray($preferences);

        $byKey = [];

        foreach ($preferences as $preference) {
            self::assertIsArray($preference);

            $category = $preference['category'] ?? null;
            $channel = $preference['channel'] ?? null;
            self::assertIsString($category);
            self::assertIsString($channel);

            $byKey[$category . ':' . $channel] = $preference;
        }

        // A client rendering a settings screen should not have to know that
        // an absent row means enabled, nor that marketing is the exception.
        self::assertTrue(self::flag($byKey, 'BILLING:EMAIL', 'enabled'));
        self::assertFalse(self::flag($byKey, 'MARKETING:EMAIL', 'enabled'));
        self::assertFalse(self::flag($byKey, 'SECURITY:EMAIL', 'mutable'));
        self::assertTrue(self::flag($byKey, 'BILLING:SMS', 'requires_consent'));
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param list<string> $channels
     */
    private function raise(string $type, string $category, array $channels, ?string $dedupKey = null): string
    {
        return $this->raiseFor($this->user, $type, $category, $channels, $dedupKey);
    }

    /**
     * @param list<string> $channels
     */
    private function raiseFor(
        string $userId,
        string $type,
        string $category,
        array $channels,
        ?string $dedupKey = null,
    ): string {
        $repository = $this->container()->get(NotificationRepository::class);
        self::assertInstanceOf(NotificationRepository::class, $repository);

        $notification = $repository->raise(
            $this->tenant,
            $this->product,
            $userId,
            $type,
            $category,
            ['amount' => 2900],
            $dedupKey,
            false,
            $channels,
        );

        return $notification->id ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(): array
    {
        $handler = $this->container()->get(DispatchNotifications::class);
        self::assertInstanceOf(DispatchNotifications::class, $handler);

        return $handler->handle($this->job());
    }

    /**
     * The dispatcher takes a Job but reads nothing from it: the work is
     * whatever is pending, not whatever the payload says. A bare queued job
     * is therefore a faithful stand-in rather than a shortcut.
     */
    private function job(): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            DispatchNotifications::TYPE,
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

    private function repository(): NotificationRepository
    {
        $repository = $this->container()->get(NotificationRepository::class);
        self::assertInstanceOf(NotificationRepository::class, $repository);

        return $repository;
    }

    /**
     * Ages the claim rather than waiting out fifteen minutes of it.
     */
    private function lapseTheLease(): void
    {
        $this->connection->executeStatement(
            "UPDATE notification_deliveries SET leased_until = now() - interval '1 minute'"
            . " WHERE status = 'SENDING'",
        );
    }

    private function attemptsOn(string $channel): int
    {
        $attempts = $this->connection->fetchOne(
            'SELECT attempts FROM notification_deliveries WHERE channel = :channel',
            ['channel' => $channel],
        );

        self::assertIsNumeric($attempts);

        return (int) $attempts;
    }

    /**
     * @return array{string, string|null}
     */
    private function deliveryState(string $channel): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, suppression_reason FROM notification_deliveries WHERE channel = :channel',
            ['channel' => $channel],
        );

        self::assertIsArray($row);

        $status = $row['status'];
        $reason = $row['suppression_reason'];

        self::assertIsString($status);

        return [$status, is_string($reason) ? $reason : null];
    }

    private function setPreference(string $category, string $channel, bool $enabled): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/notifications/preferences',
            $this->headers(),
            $this->json(['category' => $category, 'channel' => $channel, 'enabled' => $enabled]),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function grantConsent(string $channel): ResponseInterface
    {
        $response = $this->request(
            'POST',
            '/api/v1/notifications/consents',
            $this->headers(),
            $this->json(['channel' => $channel, 'purpose' => 'TRANSACTIONAL']),
        );

        self::assertSame(201, $response->getStatusCode());

        return $response;
    }

    private function read(string $notificationId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/notifications/' . $notificationId . '/read',
            $this->headers(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * The id of the newest notification, asserted down to a string so the
     * tests read as statements about notifications rather than about array
     * offsets. `decode()` gives array<string, mixed>, so one offset is typed
     * and the second is not.
     */
    private function newestId(): string
    {
        $body = $this->decode($this->request('GET', '/api/v1/notifications', $this->headers()));

        $notifications = $body['notifications'] ?? null;
        self::assertIsArray($notifications);
        self::assertNotSame([], $notifications);

        $newest = $notifications[0];
        self::assertIsArray($newest);

        $id = $newest['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * When a notification was first read, or null.
     */
    private function readAt(string $notificationId): ?string
    {
        $notification = $this->decode($this->read($notificationId))['notification'] ?? null;
        self::assertIsArray($notification);

        $readAt = $notification['read_at'] ?? null;

        return is_string($readAt) ? $readAt : null;
    }

    /**
     * One boolean out of the preference matrix.
     *
     * @param array<string, mixed> $matrix
     */
    private static function flag(array $matrix, string $key, string $field): bool
    {
        $row = $matrix[$key] ?? null;
        self::assertIsArray($row);

        $value = $row[$field] ?? null;
        self::assertIsBool($value);

        return $value;
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
