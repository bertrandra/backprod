<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\Infrastructure\PostgresAuditLog;
use App\Auth\Domain\AuthProvider;
use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\CancellationPolicy;
use App\Commerce\Domain\EarlyTerminationCharge;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Infrastructure\OfferVersionLoader;
use App\Commerce\Infrastructure\PostgresCatalogueRepository;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Commerce\Service\Subscriptions;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The free period (spec §6), end to end.
 *
 * Four claims, and each of them is a thing that goes wrong in a way nobody
 * notices for a while:
 *
 *   1. **No document at all.** Numbering is gapless, so a €0 invoice is a
 *      permanent, unremovable record of no transaction — in a series a tax
 *      authority reads. The freemium therefore has its own door and the
 *      priced chain refuses it outright.
 *   2. **Once, and once for all** — including terminated. A rule that only
 *      forbade a *second live* one would let five free days be retaken every
 *      five days, which is a product given away by recurrence. That is an
 *      index without a status filter, never an application check: two
 *      simultaneous requests walk straight through a `SELECT` then `INSERT`.
 *   3. **It does not renew.** `ENDS_AT_TERM` has been sellable since §13.1 and
 *      nothing read it, so renewal rolled it forward like anything else.
 *   4. **It is recognised by its properties**, never by a plan's name: free,
 *      and over when its period is. One of those alone is a free tier or an
 *      ordinary fixed-term contract, and both must still behave as they did.
 *
 * Against the real database and through the real pipeline, because the rule
 * that matters most here is enforced by an index and a double would be a
 * second implementation of it.
 */
#[CoversNothing]
final class FreemiumTest extends DatabaseApiTestCase
{
    /** How long this product's configuration says the free period runs. */
    private const DAYS = 5;

    private string $product = '';
    private string $otherProduct = '';
    private string $tenant = '';
    private string $user = '';
    private string $reader = '';

    private string $freemiumOffer = '';
    private string $otherFreemiumOffer = '';
    private string $pricedOffer = '';
    private string $freeTierOffer = '';
    private string $fixedTermOffer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->otherProduct = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );
        $this->reader = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bob', 'bob@example.test') RETURNING id",
        );

        $this->configure($this->product, 'Atlas SAS');
        $this->configure($this->otherProduct, 'Boreas SAS');

        // The platform's own codes, because they are what enforces them: the
        // workspace asks `max_projects` before storing a project and `users`
        // is what bounds the people a subscription covers.
        foreach (['max_projects' => 'projects', 'users' => 'users'] as $code => $unit) {
            $this->id(
                <<<'SQL'
                    INSERT INTO features (code, name, kind, unit)
                    VALUES (:code, :code, 'QUOTA', :unit) RETURNING id
                    SQL,
                ['code' => $code, 'unit' => $unit],
            );
        }

        // Four offers, and the three that are not the free period are here to
        // stop a coincidence passing for a rule: a priced offer, a free one
        // that renews, and a higher plan to move up to.
        $this->freemiumOffer = $this->seedFreemium($this->product, 'freemium');
        $this->otherFreemiumOffer = $this->seedFreemium($this->otherProduct, 'freemium-boreas');
        $this->pricedOffer = $this->seedOffer($this->product, 'pro', 40, 2900, 'MONTHLY', 'AUTO_RENEW');
        $this->freeTierOffer = $this->seedOffer($this->product, 'tier', 20, 0, 'MONTHLY', 'AUTO_RENEW');
        // And a paid plan that ends at its term, which is what makes the
        // renewal rule below about the terms rather than about the freemium.
        $this->fixedTermOffer = $this->seedOffer($this->product, 'fixed', 50, 4900, 'MONTHLY', 'ENDS_AT_TERM');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => 'sub-alice',
                'bob-token' => 'sub-bob',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
                new Product($this->otherProduct, 'boreas', 'Boreas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['USER'],
                    ['billing.pay', 'billing.read', 'subscription.read', 'subscription.manage'],
                ),
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->otherProduct,
                    ['USER'],
                    ['billing.pay', 'billing.read', 'subscription.read'],
                ),
                // Somebody who may read what they hold and not acquire it.
                // `billing.pay` is the member's own permission and this
                // membership is what proves the door asks for it.
                new TenantMembership(
                    $this->tenant,
                    $this->reader,
                    $this->product,
                    ['USER'],
                    ['subscription.read', 'billing.read'],
                ),
            ]),
        ]);

        // For the purchase that must be refused: an organisation with nowhere
        // to be invoiced would be refused for that reason instead, and prove
        // nothing about the free period.
        $this->saveProfile();
    }

    // --- What taking it does ------------------------------------------------

    public function testItStartsTheCallersOwnSeatAndSaysWhenItIsOver(): void
    {
        $response = $this->take();

        self::assertSame(201, $response->getStatusCode());

        $subscription = $this->decode($response);

        self::assertSame('ACTIVE', $subscription['status'] ?? null);
        self::assertSame($this->user, $subscription['owner_user_id'] ?? null);

        // A seat, addressed to the caller — never the organisation, which is
        // the sale the tenant surface stopped making (ADR-055).
        $subscriber = $subscription['subscriber'] ?? null;
        self::assertIsArray($subscriber);
        self::assertSame('USER', $subscriber['kind'] ?? null);
        self::assertSame($this->user, $subscriber['user_id'] ?? null);

        // It stops at its term, and it has no term in months — five days is
        // the period, written once and never rolled (§6.3).
        $terms = $subscription['terms'] ?? null;
        self::assertIsArray($terms);
        self::assertSame('ENDS_AT_TERM', $terms['renewal'] ?? null);
        self::assertArrayHasKey('term_months', $terms);
        self::assertNull($terms['term_months']);
        self::assertArrayHasKey('term_ends_at', $terms);
        self::assertNull($terms['term_ends_at']);

        $ends = $subscription['current_period_end'] ?? null;
        self::assertIsString($ends);
        self::assertEqualsWithDelta(
            (new DateTimeImmutable('+' . self::DAYS . ' days'))->getTimestamp(),
            (new DateTimeImmutable($ends))->getTimestamp(),
            120,
            'the free period is as long as the product says it is',
        );
    }

    public function testItRaisesNoOrderNoInvoiceAndNoPayment(): void
    {
        self::assertSame(201, $this->take()->getStatusCode());

        // The whole of §6.3. A €0 invoice would be a permanent hole in a
        // series that must not have one, and nothing here may create it.
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM orders'));
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM invoices'));
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM payments'));
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM vat_transactions'));

        // And the subscription says what it is, on its own row, so nothing
        // has to ask the offer version what that plan is today.
        self::assertSame(1, $this->rowsOf('SELECT count(*) FROM subscriptions WHERE is_freemium'));
    }

    public function testItGrantsWhatItSellsSoTheWorkspaceOpens(): void
    {
        self::assertSame(201, $this->take()->getStatusCode());

        self::assertSame(1, $this->rowsOf(
            <<<'SQL'
                SELECT count(*) FROM entitlements e
                JOIN features f ON f.id = e.feature_id
                WHERE f.code = 'max_projects' AND e.limit_value = 1 AND e.source = 'SUBSCRIPTION'
                SQL,
        ));
    }

    // --- Once, and once for all (§6.4) --------------------------------------

    /**
     * **The test this whole step exists for.**
     *
     * The index carries no status filter, and that is the rule rather than an
     * oversight: without it, five free days are retaken every five days and
     * the product is free for ever by recurrence. So the refusal has to
     * survive the first one being long dead.
     */
    public function testAFreePeriodThatEndedSixMonthsAgoStillForbidsAnother(): void
    {
        self::assertSame(201, $this->take()->getStatusCode());

        // Over, and swept: the clock has moved on and so has the column.
        // Nothing about this row is live any more, which is exactly the state
        // a status-filtered index would ignore.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET status = 'EXPIRED',
                       started_at = now() - interval '7 months',
                       current_period_start = now() - interval '7 months',
                       current_period_end = now() - interval '6 months',
                       ended_at = now() - interval '6 months'
                 WHERE is_freemium
                SQL,
        );

        $again = $this->take();

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('FREEMIUM_ALREADY_USED', $this->errorOf($again)['code'] ?? null);

        // And nothing was written: the database refused, so there is no
        // half-started subscription to clean up.
        self::assertSame(1, $this->rowsOf('SELECT count(*) FROM subscriptions'));
    }

    public function testASecondOneIsRefusedWhileTheFirstIsStillRunning(): void
    {
        self::assertSame(201, $this->take()->getStatusCode());

        $again = $this->take();

        // The door's refusal, in the words buying one uses, because it is the
        // same fact: one live seat per person per product.
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('SEAT_ALREADY_ACTIVE', $this->errorOf($again)['code'] ?? null);
    }

    /**
     * Per product, because a subscription always names one: tasting Atlas has
     * never said anything about Boreas.
     */
    public function testTastingOneProductLeavesTheOtherOneToTaste(): void
    {
        self::assertSame(201, $this->take()->getStatusCode());

        $other = $this->request(
            'POST',
            '/api/v1/subscription/freemium',
            $this->headers('boreas'),
            $this->json(['offer_id' => $this->otherFreemiumOffer]),
        );

        self::assertSame(201, $other->getStatusCode());
        self::assertSame(2, $this->rowsOf('SELECT count(*) FROM subscriptions WHERE is_freemium'));
    }

    /**
     * And the right it has used survives the plan being left.
     *
     * A move up to a paid plan re-snapshots the terms from the arriving
     * version, so the subscription renews afterwards — and it is still the
     * free period this account has had. That is why the column is not tied to
     * `renewal` by a CHECK: the constraint would refuse the upgrade.
     */
    public function testMovingUpOutOfItDoesNotGiveBackTheRightToAnother(): void
    {
        // Through the service rather than the endpoint, because `change-offer`
        // is addressed to the organisation's subscription and a free period is
        // a seat — see the note in the report. What is being tested is the
        // column and the absence of a constraint on it, which is the same
        // either way.
        $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freemiumOffer, $this->user);

        $moved = $this->subscriptions()->changeOffer($this->tenant, $this->product, $this->pricedOffer, $this->user);

        // The terms are re-snapshotted from the arriving version, so it renews
        // now — and the row still remembers that this account's free period is
        // spent. A CHECK tying the two would have refused this move.
        self::assertSame('AUTO_RENEW', $moved->terms->renewal);
        self::assertTrue($moved->isFreemium);
        self::assertSame(1, $this->rowsOf('SELECT count(*) FROM subscriptions WHERE is_freemium'));
    }

    // --- What is not a free period ------------------------------------------

    public function testAPricedOfferIsNotGivenAway(): void
    {
        $response = $this->take($this->pricedOffer);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('NOT_A_FREEMIUM_OFFER', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM subscriptions'));
    }

    /**
     * The conjunction, and it matters in this direction too: a free offer that
     * renews is a free *tier*. Somebody may hold it for years, so counting it
     * as their one free period would spend a right they never used.
     */
    public function testAFreeOfferThatRenewsIsATierAndNotAFreePeriod(): void
    {
        $response = $this->take($this->freeTierOffer);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('NOT_A_FREEMIUM_OFFER', $this->errorOf($response)['code'] ?? null);
    }

    public function testAProductThatHasNotSaidHowLongGivesNothing(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM product_configuration WHERE key = 'freemium' AND product_id = :product",
            ['product' => $this->product],
        );

        $response = $this->take();

        // Fail closed: guessing an interval would give away a length of time
        // nobody decided on.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('FREEMIUM_NOT_OFFERED', $this->errorOf($response)['code'] ?? null);
    }

    public function testTheFreePeriodCannotBeBoughtThroughTheCheckout(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/checkout/sessions',
            $this->headers(),
            $this->json(['offer_id' => $this->freemiumOffer]),
        );

        // Refused before an order exists, which is what keeps the €0 invoice
        // from being raised at fulfilment.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('FREEMIUM_IS_NOT_SOLD', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM orders'));
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM invoices'));
    }

    public function testWhoeverMayNotAcquireMayNotTakeItEither(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/subscription/freemium',
            ['Authorization' => 'Bearer bob-token', 'X-Product' => 'atlas'],
            $this->json(['offer_id' => $this->freemiumOffer]),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowsOf('SELECT count(*) FROM subscriptions'));
    }

    // --- It does not renew (§6.3) -------------------------------------------

    /**
     * Renewal ends what was sold as ending, instead of rolling it.
     *
     * Asked of a subscription the renewal path can reach — the organisation's,
     * since `renew()` is addressed by (tenant, product) — because the rule is
     * about the **terms** and not about the freemium: `ENDS_AT_TERM` has been
     * sellable on any offer since §13.1 and nothing read it, so a fixed-term
     * contract renewed itself for ever too.
     */
    public function testRenewalEndsWhatWasSoldAsEndingAtItsTerm(): void
    {
        $before = $this->subscriptions()->subscribe($this->tenant, $this->product, $this->freemiumOffer, $this->user);

        self::assertSame('ENDS_AT_TERM', $before->terms->renewal);

        $after = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertSame(Subscription::EXPIRED, $after->status);
        // The period is not pushed out: it ended when it ended, and the row
        // now says so. A renewal that moved the date and then expired the row
        // would have given away a period nobody asked for.
        self::assertEqualsWithDelta(
            (float) ($before->currentPeriodEnd?->getTimestamp() ?? 0),
            (float) ($after->currentPeriodEnd?->getTimestamp() ?? 0),
            1,
        );

        // And it is on the record, with its reason: a subscription that ended
        // with no trace of ending is the one gap in an otherwise complete
        // history (§18). Read from the table rather than through the service,
        // which answers for the *live* subscription and there is no longer one.
        $types = $this->connection->fetchFirstColumn(
            'SELECT type FROM subscription_events WHERE subscription_id = :id',
            ['id' => $after->id],
        );

        self::assertContains(SubscriptionEvent::EXPIRED, $types);
        self::assertNotContains(SubscriptionEvent::RENEWED, $types);
        self::assertSame(
            'ENDS_AT_TERM',
            $this->connection->fetchOne(
                "SELECT detail->>'reason' FROM subscription_events WHERE subscription_id = :id AND type = 'EXPIRED'",
                ['id' => $after->id],
            ),
        );
    }

    public function testAnOfferThatRenewsStillRenews(): void
    {
        $before = $this->subscriptions()->subscribe($this->tenant, $this->product, $this->pricedOffer, $this->user);
        $after = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertSame(Subscription::ACTIVE, $after->status);
        self::assertNotNull($before->currentPeriodEnd);
        self::assertNotNull($after->currentPeriodEnd);
        self::assertGreaterThan($before->currentPeriodEnd, $after->currentPeriodEnd);
    }

    /**
     * And a change the customer asked for beats the term, which is the order
     * §4.3 sets with the third step added to it: "does not renew" means this
     * offer does not roll into another period of itself, not that the
     * subscription may not become the thing its holder chose.
     */
    public function testAChangeAlreadyDueIsAppliedRatherThanExpired(): void
    {
        // A paid plan sold as ending at its term — an ordinary fixed-term
        // contract, which is where this rule bites outside the free period.
        $this->subscriptions()->subscribe($this->tenant, $this->product, $this->fixedTermOffer, $this->user);

        // Down to the free tier, which is a lower rank, so the change is
        // deferred to the end of the paid period — and that end is the very
        // boundary the renewal below is asked about.
        $scheduled = $this->subscriptions()->scheduleChange(
            $this->tenant,
            $this->product,
            $this->freeTierOffer,
            $this->user,
        );

        self::assertNotNull($scheduled->pending);

        $after = $this->subscriptions()->renew($this->tenant, $this->product);

        self::assertSame(Subscription::ACTIVE, $after->status);
        self::assertSame('tier', $after->offer->code);
    }

    // --- Fixtures -----------------------------------------------------------

    private function take(?string $offerId = null): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/freemium',
            $this->headers(),
            $this->json(['offer_id' => $offerId ?? $this->freemiumOffer]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $product = 'atlas'): array
    {
        return ['Authorization' => 'Bearer alice-token', 'X-Product' => $product];
    }

    private function subscriptions(): Subscriptions
    {
        return new Subscriptions(
            new PostgresSubscriptionRepository($this->connection),
            new Catalogue(new PostgresCatalogueRepository($this->connection, new OfferVersionLoader($this->connection))),
            new CancellationPolicy(),
            // Nothing here is committed, so nothing may cost anything to
            // leave. A double that fails on contact says so.
            new class () implements EarlyTerminationCharge {
                public function applyCharge(
                    Subscription $subscription,
                    CancellationDecision $decision,
                    ?string $actorUserId,
                ): string {
                    TestCase::fail('A free period charged for leaving.');
                }
            },
            new PostgresAuditLog($this->connection),
        );
    }

    /**
     * A free period: price 0, no billing period to speak of — it is never
     * billed — and a renewal that stops. One person, one project.
     */
    private function seedFreemium(string $productId, string $code): string
    {
        return $this->seedOffer(
            $productId,
            $code,
            10,
            0,
            'CUSTOM',
            'ENDS_AT_TERM',
            ['max_projects' => 1, 'users' => 1],
        );
    }

    /**
     * Draft, grant, publish — the order the application itself uses, because a
     * version's grants freeze the moment it leaves DRAFT (ADR-033) and the
     * database says so with a trigger.
     *
     * @param array<string, int|null> $grants feature code => limit
     */
    private function seedOffer(
        string $productId,
        string $code,
        int $rank,
        int $price,
        string $period,
        string $renewal,
        array $grants = [],
    ): string {
        $plan = $this->id(
            <<<'SQL'
                INSERT INTO plans (product_id, code, name, rank)
                VALUES (:product, :code, :code, :rank) RETURNING id
                SQL,
            ['product' => $productId, 'code' => $code, 'rank' => $rank],
        );

        $offer = $this->id(
            <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
                VALUES (:product, :plan, :code, :code, true) RETURNING id
                SQL,
            ['product' => $productId, 'plan' => $plan, 'code' => $code],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from, renewal)
                VALUES (:offer, 1, 'DRAFT', :period, :price, 'EUR', now() - interval '1 day', :renewal)
                RETURNING id
                SQL,
            ['offer' => $offer, 'period' => $period, 'price' => $price, 'renewal' => $renewal],
        );

        foreach ($grants as $feature => $limit) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    VALUES (:version, (SELECT id FROM features WHERE code = :feature), :limit)
                    SQL,
                ['version' => $version, 'feature' => $feature, 'limit' => $limit],
            );
        }

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        return $offer;
    }

    /**
     * The product's own facts: who bills for it, where it pays its VAT, and
     * how long it gives itself away for.
     */
    private function configure(string $productId, string $supplierName): void
    {
        $supplier = json_encode([
            'legal_name' => $supplierName,
            'vat_number' => 'FR12345678901',
            'country_code' => 'FR',
        ], JSON_THROW_ON_ERROR);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "FR", "oss_registered": true}' AS jsonb)),
                    (:product, 'freemium', CAST(:freemium AS jsonb))
                SQL,
            [
                'product' => $productId,
                'supplier' => $supplier,
                'freemium' => json_encode(['days' => self::DAYS], JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function saveProfile(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, address_line1, postal_code, city, country_code, billing_email)
                VALUES (:tenant, 'Acme SARL', '1 rue de la Paix', '75001', 'Paris', 'FR', 'billing@example.test')
                SQL,
            ['tenant' => $this->tenant],
        );
    }

    private function rowsOf(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_scalar($count) ? (int) $count : -1;
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
