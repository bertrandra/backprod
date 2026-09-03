<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Infrastructure\PostgresCatalogueRepository;
use App\Commerce\Infrastructure\PostgresEntitlementRepository;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Commerce\Service\Subscriptions;
use App\Shared\Database\Row;
use App\Shared\Exceptions\HttpException;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The subscription lifecycle against a real database.
 *
 * §37.4 names what a SaaS backend has to get right: activation, expiration,
 * renewal, upgrade, downgrade, cancellation and quota exhaustion. Every one
 * of them is here except quota exhaustion, which is exercised where it bites
 * — through the projects endpoint.
 *
 * All of it runs against PostgreSQL because the interesting behaviour is
 * transactional: a subscription, its event and its entitlements move
 * together or not at all, and entitlements lapse on a window the database
 * evaluates.
 */
#[CoversNothing]
final class SubscriptionLifecycleTest extends DatabaseTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $freeOffer = '';
    private string $proOffer = '';
    private string $projectsFeature = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->seedProduct('atlas');
        $this->tenant = $this->seedTenant('acme');
        $this->user = $this->seedUser('sub-alice');

        $free = $this->seedPlan('FREE', 10);
        $pro = $this->seedPlan('PRO', 20);

        $this->projectsFeature = $this->seedFeature('max_projects', 'QUOTA', 'projects');
        $advanced = $this->seedFeature('advanced_3d', 'BOOLEAN', null);

        $this->freeOffer = $this->seedOffer($free, 'free');
        $freeVersion = $this->seedVersion($this->freeOffer, 0, 'MONTHLY');
        $this->grant($freeVersion, $this->projectsFeature, 3);

        $this->proOffer = $this->seedOffer($pro, 'pro');
        $proVersion = $this->seedVersion($this->proOffer, 2900, 'MONTHLY');
        $this->grant($proVersion, $this->projectsFeature, 50);
        $this->grant($proVersion, $advanced, null);
    }

    // --- Activation ---------------------------------------------------------

    public function testActivationGrantsWhatTheOfferPromised(): void
    {
        $subscription = $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        );

        self::assertSame(Subscription::ACTIVE, $subscription->status);
        self::assertSame('pro', $subscription->offer->code);
        self::assertNotNull($subscription->currentPeriodEnd, 'a monthly offer has a period end');

        // The point of the milestone: capabilities now come from what was
        // bought, and the context chain reads exactly these.
        self::assertSame(
            ['advanced_3d', 'max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
        );

        $limits = $this->entitlements()->entitlementsFor($this->tenant, $this->product);
        self::assertSame(50, $limits[1]->limit);
        self::assertSame('SUBSCRIPTION', $limits[1]->source);
    }

    public function testActivationIsRecorded(): void
    {
        $this->subscribeToPro();

        $events = $this->subscriptions()->events($this->tenant, $this->product);

        self::assertCount(1, $events);
        self::assertSame(SubscriptionEvent::ACTIVATED, $events[0]->type);
        self::assertSame($this->user, $events[0]->actorUserId);
    }

    public function testATenantCannotHoldTwoSubscriptionsForOneProduct(): void
    {
        $this->subscribeToPro();

        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->freeOffer,
            $this->user,
        ));

        self::assertSame(409, $error->statusCode());
        self::assertSame('ALREADY_SUBSCRIBED', $error->errorCode());
    }

    /**
     * Buying goes through the catalogue, so an offer that is not for sale
     * cannot be subscribed to by naming its id.
     */
    public function testAWithdrawnOfferCannotBeSubscribedTo(): void
    {
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED' WHERE offer_id = :offer",
            ['offer' => $this->proOffer],
        );

        $error = $this->refusal(fn (): Subscription => $this->subscriptions()->subscribe(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        ));

        self::assertSame(404, $error->statusCode());
        self::assertSame('OFFER_NOT_FOUND', $error->errorCode());
    }

    // --- Expiration ---------------------------------------------------------

    /**
     * The milestone's central claim, and the reason entitlements carry a
     * window: nothing runs at midnight, and the tenant still stops being
     * entitled. The status column is deliberately left saying ACTIVE.
     */
    public function testEntitlementsLapseOnTheClockWithNothingSweepingThem(): void
    {
        $this->subscribeToPro();

        self::assertNotSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));

        // Move the period into the past, exactly as time passing would.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET current_period_start = now() - interval '2 months',
                       current_period_end = now() - interval '1 month'
                 WHERE tenant_id = :tenant
                SQL,
            ['tenant' => $this->tenant],
        );
        // Both ends move: valid_until must stay after valid_from, and a
        // lapsed entitlement is one whose whole window is in the past.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE entitlements
                   SET valid_from = now() - interval '2 months',
                       valid_until = now() - interval '1 month'
                 WHERE tenant_id = :tenant
                SQL,
            ['tenant' => $this->tenant],
        );

        self::assertSame(
            'ACTIVE',
            $this->statusOf(),
            'the row still says active — nothing has swept it',
        );
        self::assertSame(
            [],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'and the tenant is entitled to nothing anyway',
        );
        self::assertNull(
            $this->subscriptions()->current($this->tenant, $this->product),
            'and the service reports no live subscription',
        );
    }

    // --- Renewal ------------------------------------------------------------

    public function testRenewalExtendsThePeriodAndTheEntitlementsTogether(): void
    {
        $subscription = $this->subscribeToPro();
        $firstEnd = $subscription->currentPeriodEnd;
        self::assertNotNull($firstEnd);

        $renewed = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertNotNull($renewed->currentPeriodEnd);
        self::assertGreaterThan($firstEnd, $renewed->currentPeriodEnd);

        // The new period starts where the old one ended, so renewing late
        // leaves no gap the tenant was unentitled for.
        self::assertSame(
            $firstEnd->format(DATE_ATOM),
            $renewed->currentPeriodStart->format(DATE_ATOM),
        );

        // And the entitlements moved with it, rather than lapsing under a
        // subscription that is still being paid for.
        self::assertSame(
            ['advanced_3d', 'max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
        );
        self::assertSame(
            $renewed->currentPeriodEnd->format(DATE_ATOM),
            $this->entitlementEnd()?->format(DATE_ATOM),
        );
    }

    // --- Upgrade and downgrade ----------------------------------------------

    public function testAnUpgradeReplacesTheGrantsAndSaysWhichDirectionItWas(): void
    {
        $this->subscribeToFree();
        self::assertSame(3, $this->limitFor('max_projects'));

        $upgraded = $this->subscriptions()->changeOffer(
            $this->tenant,
            $this->product,
            $this->proOffer,
            $this->user,
        );

        self::assertSame('pro', $upgraded->offer->code);
        self::assertSame(50, $this->limitFor('max_projects'));
        self::assertContains('advanced_3d', $this->entitlements()->capabilitiesFor($this->tenant, $this->product));

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::OFFER_CHANGED, $latest->type);
        self::assertSame(Subscriptions::UPGRADE, $latest->detail->direction ?? null);
    }

    public function testADowngradeTakesTheExtraGrantsAway(): void
    {
        $this->subscribeToPro();

        $this->subscriptions()->changeOffer($this->tenant, $this->product, $this->freeOffer, $this->user);

        self::assertSame(
            ['max_projects'],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'the boolean capability the pro offer granted is gone',
        );
        self::assertSame(3, $this->limitFor('max_projects'));

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(Subscriptions::DOWNGRADE, $latest->detail->direction ?? null);
    }

    /**
     * A change keeps the period the tenant already paid for. Prorating money
     * is billing, and billing is M6.
     */
    public function testAChangeDoesNotMoveThePeriod(): void
    {
        $before = $this->subscribeToPro();
        $after = $this->subscriptions()->changeOffer(
            $this->tenant,
            $this->product,
            $this->freeOffer,
            $this->user,
        );

        self::assertSame(
            $before->currentPeriodEnd?->format(DATE_ATOM),
            $after->currentPeriodEnd?->format(DATE_ATOM),
        );
    }

    /**
     * A negotiated exception is not a subscription grant, and must survive a
     * plan change — otherwise support's deliberate act quietly disappears
     * the next time the customer upgrades.
     */
    public function testAnOverrideSurvivesAChangeOfOffer(): void
    {
        $this->subscribeToFree();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO entitlements (tenant_id, product_id, feature_id, limit_value, source)
                VALUES (:tenant, :product, :feature, 500, 'OVERRIDE')
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'feature' => $this->projectsFeature],
        );

        $this->subscriptions()->changeOffer($this->tenant, $this->product, $this->proOffer, $this->user);

        // The override outranks both grants: most generous wins.
        self::assertSame(500, $this->limitFor('max_projects'));
        self::assertSame('OVERRIDE', $this->sourceFor('max_projects'));
    }

    // --- Cancellation -------------------------------------------------------

    public function testASimpleCancellationKeepsTheMonthAlreadyPaidFor(): void
    {
        $this->subscribeToPro();

        $cancelled = $this->subscriptions()->cancel($this->tenant, $this->product, false, $this->user);

        self::assertSame(Subscription::ACTIVE, $cancelled->status);
        self::assertTrue($cancelled->cancelAtPeriodEnd);
        self::assertNotSame(
            [],
            $this->entitlements()->capabilitiesFor($this->tenant, $this->product),
            'still entitled until the period ends',
        );

        $latest = $this->subscriptions()->events($this->tenant, $this->product)[0];
        self::assertSame(SubscriptionEvent::CANCELLATION_SCHEDULED, $latest->type);
    }

    public function testAnImmediateCancellationEndsTheEntitlementsWithIt(): void
    {
        $this->subscribeToPro();

        $cancelled = $this->subscriptions()->cancel($this->tenant, $this->product, true, $this->user);

        self::assertSame(Subscription::CANCELLED, $cancelled->status);
        self::assertNotNull($cancelled->endedAt);
        self::assertSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));
        self::assertNull($this->subscriptions()->current($this->tenant, $this->product));
    }

    public function testResumingWithdrawsAScheduledCancellation(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->cancel($this->tenant, $this->product, false, $this->user);

        $resumed = $this->subscriptions()->resume($this->tenant, $this->product, $this->user);

        self::assertFalse($resumed->cancelAtPeriodEnd);
        self::assertNull($resumed->cancelledAt);
    }

    public function testResumingASubscriptionThatIsNotEndingIsRefused(): void
    {
        $this->subscribeToPro();

        $error = $this->refusal(
            fn (): Subscription => $this->subscriptions()->resume($this->tenant, $this->product, $this->user),
        );

        self::assertSame(409, $error->statusCode());
        self::assertSame('NOT_CANCELLING', $error->errorCode());
    }

    /**
     * After an immediate cancellation the tenant may subscribe again — the
     * partial unique index only forbids two *active* at once.
     */
    public function testATenantMaySubscribeAgainAfterCancelling(): void
    {
        $this->subscribeToPro();
        $this->subscriptions()->cancel($this->tenant, $this->product, true, $this->user);

        $again = $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freeOffer, $this->user);

        self::assertSame(Subscription::ACTIVE, $again->status);
        self::assertCount(2, $this->subscriptions()->history($this->tenant, $this->product));
    }

    // --- The exit criterion --------------------------------------------------

    /**
     * "Expiring an offer leaves historical subscriptions intact and
     * readable." Structural rather than conventional: the foreign key is
     * RESTRICT, so the version cannot even be deleted.
     */
    public function testExpiringAnOfferLeavesTheSubscriptionReadable(): void
    {
        $subscription = $this->subscribeToPro();
        $versionId = $subscription->offer->version->id;

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :id",
            ['id' => $versionId],
        );

        // Gone from the catalogue…
        self::assertSame([], (new Catalogue($this->catalogue()))->offersOnSale($this->product));

        // …and still completely legible to the tenant who bought it.
        $reloaded = $this->subscriptions()->current($this->tenant, $this->product);
        self::assertNotNull($reloaded);
        self::assertSame('pro', $reloaded->offer->code);
        self::assertSame(2900, $reloaded->offer->version->priceMinorUnits);
        self::assertSame('EUR', $reloaded->offer->version->currency);
        self::assertSame(OfferVersion::EXPIRED, $reloaded->offer->version->status);
        self::assertCount(2, $reloaded->offer->version->grants);

        // And the tenant is still entitled: withdrawing an offer from sale
        // does not cancel the people already on it.
        self::assertNotSame([], $this->entitlements()->capabilitiesFor($this->tenant, $this->product));
    }

    public function testASubscribedOfferVersionCannotBeDeleted(): void
    {
        $subscription = $this->subscribeToPro();

        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            'DELETE FROM offer_versions WHERE id = :id',
            ['id' => $subscription->offer->version->id],
        );
    }

    // --- Helpers -------------------------------------------------------------

    private function subscribeToPro(): Subscription
    {
        return $this->subscriptions()->subscribe($this->tenant, $this->product, $this->proOffer, $this->user);
    }

    private function subscribeToFree(): Subscription
    {
        return $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freeOffer, $this->user);
    }

    private function subscriptions(): Subscriptions
    {
        return new Subscriptions(
            new PostgresSubscriptionRepository($this->connection),
            new Catalogue($this->catalogue()),
        );
    }

    private function catalogue(): PostgresCatalogueRepository
    {
        return new PostgresCatalogueRepository($this->connection);
    }

    private function entitlements(): PostgresEntitlementRepository
    {
        return new PostgresEntitlementRepository($this->connection);
    }

    /**
     * @param callable(): Subscription $act
     */
    private function refusal(callable $act): HttpException
    {
        try {
            $act();
        } catch (HttpException $error) {
            return $error;
        }

        self::fail('The operation was allowed.');
    }

    private function statusOf(): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM subscriptions WHERE tenant_id = :tenant',
            ['tenant' => $this->tenant],
        );

        return is_string($status) ? $status : '';
    }

    private function limitFor(string $featureCode): ?int
    {
        foreach ($this->entitlements()->entitlementsFor($this->tenant, $this->product) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement->limit;
            }
        }

        return null;
    }

    private function sourceFor(string $featureCode): ?string
    {
        foreach ($this->entitlements()->entitlementsFor($this->tenant, $this->product) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement->source;
            }
        }

        return null;
    }

    private function entitlementEnd(): ?DateTimeImmutable
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT valid_until FROM entitlements
                 WHERE tenant_id = :tenant AND source = 'SUBSCRIPTION'
                 ORDER BY valid_until DESC LIMIT 1
                SQL,
            ['tenant' => $this->tenant],
        );

        return $row === false ? null : Row::nullableTimestamp($row, 'valid_until');
    }

    private function seedProduct(string $code): string
    {
        return $this->id(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
        );
    }

    private function seedTenant(string $slug): string
    {
        return $this->id(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => ucfirst($slug), 'slug' => $slug],
        );
    }

    private function seedUser(string $subject): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject) VALUES (:subject) RETURNING id',
            ['subject' => $subject],
        );
    }

    private function seedPlan(string $code, int $rank): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO plans (product_id, code, name, rank)
                VALUES (:product, :code, :name, :rank) RETURNING id
                SQL,
            ['product' => $this->product, 'code' => $code, 'name' => $code, 'rank' => $rank],
        );
    }

    private function seedFeature(string $code, string $kind, ?string $unit): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO features (product_id, code, name, kind, unit)
                VALUES (:product, :code, :code, :kind, :unit) RETURNING id
                SQL,
            ['product' => $this->product, 'code' => $code, 'kind' => $kind, 'unit' => $unit],
        );
    }

    private function seedOffer(string $planId, string $code): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name)
                VALUES (:product, :plan, :code, :code) RETURNING id
                SQL,
            ['product' => $this->product, 'plan' => $planId, 'code' => $code],
        );
    }

    private function seedVersion(string $offerId, int $price, string $period): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'ACTIVE', :period, :price, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $offerId, 'period' => $period, 'price' => $price],
        );
    }

    private function grant(string $versionId, string $featureId, ?int $limit): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                VALUES (:version, :feature, :limit)
                SQL,
            ['version' => $versionId, 'feature' => $featureId, 'limit' => $limit],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }
}
