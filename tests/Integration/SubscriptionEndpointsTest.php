<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Domain\Subscription;
use App\Commerce\Service\Subscriptions;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Exceptions\HttpException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Subscribing, and what it changes, through the real pipeline and the real
 * database.
 *
 * The doubles stop at identity: who is calling, which product, and what they
 * are a member of. Everything downstream — the catalogue, the subscription,
 * the entitlements it grants, the quota those entitlements impose — is the
 * production code against PostgreSQL, because the behaviour under test is
 * precisely how those pieces move together.
 *
 * This is where §37.4's last item lands: quota exhaustion, exercised where a
 * customer would actually meet it.
 */
#[CoversNothing]
final class SubscriptionEndpointsTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $freeOffer = '';
    private string $proOffer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id",
        );
        // Seeded rather than provisioned so the membership double can name
        // the same id the context will resolve. The real directory finds it.
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );

        $this->seedCatalogue();

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => 'sub-alice',
                'bob-token' => 'sub-bob',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    [
                        'subscription.read',
                        'subscription.manage',
                        'entitlements.read',
                        'catalog.read',
                        'projects.read',
                        'projects.write',
                        // A move up raises a document since 2026-09-27
                        // (spec §3), so the organisation needs an identity to
                        // put on it — which it sets through these two.
                        'billing.manage',
                        'tax.manage',
                    ],
                ),
            ]),
        ]);
    }

    public function testATenantWithNoSubscriptionIsNotAnError(): void
    {
        $response = $this->request('GET', '/api/v1/subscription', $this->headers());

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertArrayHasKey('subscription', $body);
        self::assertNull($body['subscription']);
        self::assertSame([], $body['history'] ?? null);
    }

    public function testSubscribingGrantsTheOffersCapabilities(): void
    {
        $subscription = $this->subscribeTo($this->proOffer);

        self::assertSame('ACTIVE', $subscription->status);

        // The §10.6 chain now resolves capabilities from what was bought.
        $mine = $this->decode($this->request('GET', '/api/v1/me/entitlements', $this->headers()));
        self::assertSame(['advanced_3d', 'max_projects'], $mine['capabilities'] ?? null);
    }

    public function testSubscribingToAnUnknownOfferIsRefused(): void
    {
        $refused = null;

        try {
            $this->subscribeTo('2f1c1c8e-0000-4000-8000-000000000000');
        } catch (HttpException $error) {
            $refused = $error;
        }

        self::assertInstanceOf(HttpException::class, $refused);
        self::assertSame(404, $refused->statusCode());
        self::assertSame('OFFER_NOT_FOUND', $refused->errorCode());
    }

    public function testEntitlementsReportTheirLimits(): void
    {
        $this->subscribeTo($this->freeOffer);

        $entitlements = $this->decode(
            $this->request('GET', '/api/v1/entitlements', $this->headers()),
        )['entitlements'] ?? null;

        self::assertIsArray($entitlements);

        $first = $entitlements[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('max_projects', $first['feature'] ?? null);
        self::assertSame(2, $first['limit'] ?? null);
        self::assertFalse($first['unlimited'] ?? null);
        self::assertSame('SUBSCRIPTION', $first['source'] ?? null);
    }

    /**
     * The gap between what an offer grants and what the platform can count is
     * reported, not hidden. Projects are counted; storage is not, because
     * storage does not exist until M7 — and saying "0 used" about it would
     * tell a customer a limit is being enforced when nothing enforces it.
     */
    public function testUsageSaysWhatIsMeasuredAndWhatIsNot(): void
    {
        $this->subscribeTo($this->freeOffer);
        $this->createProject('One');

        $usage = $this->decode(
            $this->request('GET', '/api/v1/tenants/current/usage', $this->headers()),
        )['usage'] ?? null;

        self::assertIsArray($usage);
        self::assertCount(2, $usage, 'both quotas, and only the quotas');

        $projects = $usage[0] ?? null;
        self::assertIsArray($projects);
        self::assertSame('max_projects', $projects['feature'] ?? null);
        self::assertTrue($projects['metered'] ?? null);
        self::assertSame(1, $projects['used'] ?? null);
        self::assertSame(1, $projects['remaining'] ?? null);

        $storage = $usage[1] ?? null;
        self::assertIsArray($storage);
        self::assertSame('max_storage', $storage['feature'] ?? null);
        self::assertFalse($storage['metered'] ?? null, 'nothing counts storage yet');
        self::assertSame(1_000_000, $storage['limit'] ?? null, 'the limit is still recorded');
        self::assertArrayHasKey('used', $storage);
        self::assertNull($storage['used'], 'and no number is invented for it');
        self::assertArrayHasKey('remaining', $storage);
        self::assertNull($storage['remaining']);
    }

    // --- §37.4: quota exhaustion --------------------------------------------

    /**
     * The whole chain in one test: an offer grants two projects, two are
     * created, and the third is refused — by the quota, not by anything the
     * project code knows about commerce.
     */
    public function testAQuotaIsEnforcedWhereACustomerMeetsIt(): void
    {
        $this->subscribeTo($this->freeOffer);

        self::assertSame(201, $this->createProject('One')->getStatusCode());
        self::assertSame(201, $this->createProject('Two')->getStatusCode());

        $third = $this->createProject('Three');

        self::assertSame(403, $third->getStatusCode());

        $error = $this->errorOf($third);
        self::assertSame('QUOTA_EXCEEDED', $error['code'] ?? null);

        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('max_projects', $details['capability'] ?? null);
        self::assertSame(2, $details['limit'] ?? null);
        self::assertSame(2, $details['used'] ?? null);
    }

    /**
     * And the way out of it is commercial: upgrading raises the limit, and
     * the very next request succeeds. Nothing about the project endpoint
     * changed.
     *
     * What did change is that the upgrade is now **priced** (2026-09-27, spec
     * §3), so the organisation has to have said who it is before a document can
     * be raised against it — which is why this scenario now sets a billing
     * identity first. The refusal without one is
     * {@see self::testAPricedMoveUpNeedsSomebodyToInvoice()}.
     */
    public function testUpgradingClearsTheQuota(): void
    {
        $this->beInvoiceable();
        $this->subscribeTo($this->freeOffer);
        $this->createProject('One');
        $this->createProject('Two');
        self::assertSame(403, $this->createProject('Three')->getStatusCode());

        $changed = $this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer]),
        );
        self::assertSame(200, $changed->getStatusCode());

        self::assertSame(201, $this->createProject('Three')->getStatusCode());

        // And the move said what it cost, rather than answering "changed:
        // true". Nothing was collected for the free period, so there is
        // nothing to credit and the whole new period is payable.
        $change = $this->decode($changed)['change'] ?? null;
        self::assertIsArray($change);
        self::assertSame('UPGRADE', $change['direction'] ?? null);
        self::assertSame(0, $change['credit_minor_units'] ?? null);
        self::assertSame(3_480, $change['charge_minor_units'] ?? null, '€29.00 plus 20% French VAT');
        self::assertSame(3_480, $change['net_minor_units'] ?? null);
        self::assertIsString($change['charge_invoice_id'] ?? null);
        self::assertNull($change['credit_refund_id'] ?? null);
    }

    /**
     * A priced move up needs somebody to invoice (2026-09-27).
     *
     * The same refusal placing an order makes, for the same reason: numbering
     * is gapless, so a document raised against nobody cannot be deleted
     * afterwards. And it is refused **before anything moves** — the
     * subscription is still on the plan it was, which is what makes this a
     * refusal rather than a half-finished upgrade.
     */
    public function testAPricedMoveUpNeedsSomebodyToInvoice(): void
    {
        $this->subscribeTo($this->freeOffer);

        $refused = $this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer]),
        );

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($refused)['code'] ?? null);

        self::assertSame('free', $this->currentOfferCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    /**
     * The preview, which is the same calculation with nothing written
     * (spec §7).
     *
     * Both directions, because §7's table has a row for each: a move up says
     * what is payable today, a move down says the date it takes effect and
     * that it costs nothing. And the preview writes no document — the
     * assertion below is what says so.
     */
    public function testThePreviewAnswersBothDirectionsAndWritesNothing(): void
    {
        $this->beInvoiceable();
        $this->subscribeTo($this->freeOffer);

        $up = $this->decode($this->preview($this->proOffer))['if_changed_now'] ?? null;
        self::assertIsArray($up);
        self::assertTrue($up['accepted'] ?? null);
        self::assertSame('UPGRADE', $up['direction'] ?? null);
        self::assertSame('IMMEDIATE', $up['effect'] ?? null);
        self::assertSame(3_480, $up['charge_minor_units'] ?? null);
        self::assertSame(0, $up['credit_minor_units'] ?? null);
        self::assertSame(3_480, $up['net_minor_units'] ?? null);
        self::assertIsString($up['new_period_end'] ?? null);
        self::assertSame('change.prorated_now', $up['rule_id'] ?? null);

        // Now the other way round, from the dearer plan.
        $this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer]),
        );

        $down = $this->decode($this->preview($this->freeOffer))['if_changed_now'] ?? null;
        self::assertIsArray($down);
        self::assertTrue($down['accepted'] ?? null);
        self::assertSame('DOWNGRADE', $down['direction'] ?? null);
        self::assertSame('AT_PERIOD_END', $down['effect'] ?? null);
        self::assertSame(0, $down['charge_minor_units'] ?? null);
        self::assertSame(0, $down['credit_minor_units'] ?? null);
        self::assertSame('change.deferred_to_period_end', $down['rule_id'] ?? null);
        // The date is the end of the period already paid for, which is the
        // subscription's own and not a subtraction anywhere.
        self::assertSame($this->currentPeriodEnd(), $down['effective_at'] ?? null);

        // One invoice — the upgrade's. Two previews raised none, which is the
        // property that lets a catalogue screen ask about every offer it shows.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM invoices'));
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM credit_notes'));
    }

    /**
     * And the preview cannot disagree with the act, because it is the same
     * calculation: the refusal a change would meet is the refusal the preview
     * reports.
     */
    public function testThePreviewRefusesWhatTheChangeWouldRefuse(): void
    {
        $this->beInvoiceable();
        $this->subscribeTo($this->proOffer);

        $refused = $this->preview($this->proOffer);

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('ALREADY_ON_OFFER', $this->errorOf($refused)['code'] ?? null);
    }

    private function preview(string $offerId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/preview-change',
            $this->headers(),
            $this->json(['offer_id' => $offerId]),
        );
    }

    /**
     * Who the organisation is, fiscally and on a document.
     *
     * The three answers a priced move up needs: the product's own billing
     * identity, the organisation's legal identity, and its fiscal position.
     * Set through the endpoints that own them rather than in SQL, so the
     * fixture cannot describe a state the platform would not let a customer
     * reach.
     */
    private function beInvoiceable(): void
    {
        $supplier = json_encode(['legal_name' => 'Atlas SAS', 'vat_number' => 'FR12345678901', 'country_code' => 'FR']);
        self::assertIsString($supplier);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "FR", "oss_registered": true}' AS jsonb))
                SQL,
            ['product' => $this->product, 'supplier' => $supplier],
        );

        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers(),
            $this->json(['legal_name' => 'Acme SARL', 'country_code' => 'FR', 'city' => 'Paris']),
        )->getStatusCode());

        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/tax/profile',
            $this->headers(),
            $this->json(['customer_kind' => 'B2C', 'country_code' => 'FR']),
        )->getStatusCode());
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The read every assertion about "did it move?" goes through.
     *
     * @return array<mixed>
     */
    private function currentSubscription(): array
    {
        $subscription = $this->decode(
            $this->request('GET', '/api/v1/subscription', $this->headers()),
        )['subscription'] ?? null;

        self::assertIsArray($subscription);

        return $subscription;
    }

    private function currentOfferCode(): mixed
    {
        $offer = $this->currentSubscription()['offer'] ?? null;
        self::assertIsArray($offer);

        return $offer['code'] ?? null;
    }

    private function currentPeriodEnd(): mixed
    {
        return $this->currentSubscription()['current_period_end'] ?? null;
    }

    /**
     * Without a subscription a tenant may create nothing. Absence grants
     * nothing — the same rule everywhere in this platform.
     */
    public function testWithoutASubscriptionThereIsNoEntitlementAtAll(): void
    {
        $response = $this->createProject('One');

        self::assertSame(403, $response->getStatusCode());
        // `SUBSCRIPTION_REQUIRED` since 2026-09-25 (ADR-053), and the change
        // of code is the improvement rather than a side effect: the refusal
        // used to say "your organisation did not buy this feature", when
        // what is true is that no subscription covers this person at all.
        // The first sends somebody to a price list; the second sends them to
        // whoever owns the subscription, which is where the answer is.
        self::assertSame('SUBSCRIPTION_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    // --- Cancelling ----------------------------------------------------------

    public function testCancellingAtPeriodEndKeepsTheCapabilities(): void
    {
        $this->subscribeTo($this->proOffer);

        // No body at all: json_encode([]) is "[]", a JSON array, which the
        // body reader refuses — and rightly, since it is not an object.
        $cancelled = $this->request('POST', '/api/v1/subscription/cancel', $this->headers());

        self::assertSame(200, $cancelled->getStatusCode());
        self::assertTrue($this->decode($cancelled)['cancel_at_period_end'] ?? null);

        $mine = $this->decode($this->request('GET', '/api/v1/me/entitlements', $this->headers()));
        self::assertSame(['advanced_3d', 'max_projects'], $mine['capabilities'] ?? null);
    }

    public function testCancellingImmediatelyTakesThemAway(): void
    {
        $this->subscribeTo($this->proOffer);

        $this->request(
            'POST',
            '/api/v1/subscription/cancel',
            $this->headers(),
            $this->json(['immediately' => true]),
        );

        $mine = $this->decode($this->request('GET', '/api/v1/me/entitlements', $this->headers()));
        self::assertSame([], $mine['capabilities'] ?? null);
    }

    public function testAnImmediatelyFlagMustBeABoolean(): void
    {
        $this->subscribeTo($this->proOffer);

        $response = $this->request(
            'POST',
            '/api/v1/subscription/cancel',
            $this->headers(),
            $this->json(['immediately' => 'yes']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    // --- Permissions ---------------------------------------------------------

    /**
     * A member may see what the tenant is on; only somebody holding
     * `subscription.manage` may change it. Both are role questions, and
     * neither is an entitlement one.
     *
     * It used to prove this on `POST /subscription`, which is gone
     * (ADR-055). Changing what is held is `change-offer`, and the refusal is
     * the same one.
     */
    public function testChangingASubscriptionNeedsThePermission(): void
    {
        $this->subscribeTo($this->freeOffer);

        $this->override([
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['USER'],
                    ['subscription.read'],
                ),
            ]),
        ]);

        self::assertSame(200, $this->request('GET', '/api/v1/subscription', $this->headers())->getStatusCode());

        $response = $this->request(
            'POST',
            '/api/v1/subscription/change-offer',
            $this->headers(),
            $this->json(['offer_id' => $this->proOffer]),
        );

        self::assertSame(403, $response->getStatusCode());

        $details = $this->errorOf($response)['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('subscription.manage', $details['permission'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Subscribing, through the service.
     *
     * `POST /api/v1/subscription` did this until 2026-09-25 and is gone: it
     * started a subscription with no invoice and no payment (ADR-024's rule)
     * and any member could reach it (ADR-055's). The platform still does this
     * when it holds one itself, so the fixture calls what the platform calls.
     */
    private function subscribeTo(string $offerId): Subscription
    {
        $subscriptions = $this->container()->get(Subscriptions::class);

        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        return $subscriptions->subscribe($this->tenant, $this->product, $offerId, $this->user, $this->user);
    }

    private function createProject(string $name): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/projects',
            $this->headers(),
            $this->json(['name' => $name, 'schema_version' => 1, 'document' => (object) []]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => 'atlas'];
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

        $plan = 'INSERT INTO plans (product_id, code, name, rank)'
            . ' VALUES (:product, :code, :code, :rank) RETURNING id';

        $free = $this->id($plan, ['product' => $this->product, 'code' => 'FREE', 'rank' => 10]);
        $pro = $this->id($plan, ['product' => $this->product, 'code' => 'PRO', 'rank' => 20]);

        $feature = 'INSERT INTO features (code, name, kind, unit)'
            . ' VALUES (:code, :code, :kind, :unit) RETURNING id';

        $projects = $this->id($feature, [
            'product' => $this->product,
            'code' => 'max_projects',
            'kind' => 'QUOTA',
            'unit' => 'projects',
        ]);
        $advanced = $this->id($feature, [
            'product' => $this->product,
            'code' => 'advanced_3d',
            'kind' => 'BOOLEAN',
            'unit' => null,
        ]);
        // A quota nothing counts yet: storage arrives in M7. It is here to
        // prove the platform says so rather than reporting a confident zero.
        $storage = $this->id($feature, [
            'product' => $this->product,
            'code' => 'max_storage',
            'kind' => 'QUOTA',
            'unit' => 'bytes',
        ]);

        $this->freeOffer = $this->offer($free, 'free', 0, [[$projects, 2], [$storage, 1_000_000]]);
        $this->proOffer = $this->offer($pro, 'pro', 2900, [[$projects, 50], [$advanced, null]]);
    }

    /**
     * @param list<array{string, int|null}> $grants
     */
    private function offer(string $planId, string $code, int $price, array $grants): string
    {
        $offerId = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . ' VALUES (:product, :plan, :code, :code) RETURNING id',
            ['product' => $this->product, 'plan' => $planId, 'code' => $code],
        );

        $versionId = $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'DRAFT', 'MONTHLY', :price, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $offerId, 'price' => $price],
        );

        foreach ($grants as [$featureId, $limit]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    VALUES (:version, :feature, :limit)
                    SQL,
                ['version' => $versionId, 'feature' => $featureId, 'limit' => $limit],
            );
        }

        // Seeded the way the product builds one: DRAFT, then its grants,
        // then published. A version's grants are frozen once it leaves DRAFT
        // (ADR-033), so attaching them to an ACTIVE row is an order nothing
        // in the platform actually uses.
        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $versionId],
        );

        return $offerId;
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
