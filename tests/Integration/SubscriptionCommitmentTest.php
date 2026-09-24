<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Infrastructure\PostgresEntitlementRepository;
use App\Entitlement\Domain\QuotaPolicy;
use App\Entitlement\Domain\UsageMeter;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Exceptions\HttpException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\FixedUsage;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §13.1 through the real pipeline and the real database: what a subscription
 * commits to, who it entitles, and what leaving it costs.
 *
 * The distinction every scenario here turns on is the one that is easiest to
 * lose:
 *
 *     the payment period is not the commitment
 *
 * A 24-month subscription billed monthly is one 24-month commitment billed
 * 24 times. Every refusal below exists because that sentence has to survive
 * contact with a customer who wants to leave in month three.
 *
 * Refusals come first, per §37.4: a platform that lets people out of
 * commitments is not fixed by a test proving it also lets them stay.
 */
#[CoversNothing]
final class SubscriptionCommitmentTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $colleague = '';

    /** Commitment of 12 months, no way out before it ends. */
    private string $lockedOffer = '';

    /** The same commitment, but the exit is sold: pay what remains. */
    private string $buyoutOffer = '';

    /** The same commitment, and leaving early costs nothing. */
    private string $freeExitOffer = '';

    /** Committed on price, but cancellable whenever. */
    private string $anytimeOffer = '';

    /** A fixed 24-month term with a 12-month commitment inside it. */
    private string $termOffer = '';

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
        $this->colleague = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bob', 'bob@example.test') RETURNING id",
        );

        $this->seedCatalogue();
        $this->seedBillingIdentity();

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => 'sub-alice',
                'bob-token' => 'sub-bob',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                $this->membership($this->user),
                $this->membership($this->colleague),
            ]),
        ]);
    }

    // --- Refusals -----------------------------------------------------------

    /**
     * The commitment is the whole point: a customer who signed for twelve
     * months does not leave in month one because they asked twice.
     */
    public function testLeavingNowUnderAClosedCommitmentDoesNotEndItNow(): void
    {
        $this->subscribeTo($this->lockedOffer);

        $body = $this->decode($this->cancel(immediately: true));
        $cancellation = $this->cancellationIn($body);

        // Not a 409: the request is honoured, just not on the terms asked
        // for. Refusing outright would leave the customer with no way to
        // signal that they want out at all.
        self::assertSame('ACTIVE', $body['status'] ?? null);
        self::assertSame('AT_COMMITMENT_END', $cancellation['effect'] ?? null);
        self::assertSame('cancel.deferred_to_commitment_end', $cancellation['rule_id'] ?? null);

        // And it costs nothing, because nothing was bought out.
        self::assertArrayHasKey('charge_invoice_id', $cancellation);
        self::assertNull($cancellation['charge_invoice_id']);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    /**
     * A seat is held by a person, and a second one for the same person is
     * refused — the partial unique index decides under concurrency, this is
     * the message a client can act on.
     */
    public function testTheSamePersonCannotTakeTwoSeats(): void
    {
        self::assertSame(201, $this->subscribeTo($this->anytimeOffer, seat: true)->getStatusCode());

        $again = $this->subscribeTo($this->anytimeOffer, seat: true);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ALREADY_SUBSCRIBED', $this->errorOf($again)['code'] ?? null);
    }

    /**
     * A tenant subscription and a seat are different scopes, so holding one
     * does not block the other.
     */
    public function testASeatAndATenantSubscriptionCoexist(): void
    {
        self::assertSame(201, $this->subscribeTo($this->anytimeOffer)->getStatusCode());
        self::assertSame(201, $this->subscribeTo($this->anytimeOffer, seat: true)->getStatusCode());

        self::assertSame(
            2,
            $this->rowsMatching("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"),
        );
    }

    // --- What the commitment does -------------------------------------------

    /**
     * Sold as cancellable at any time, the commitment binds the price rather
     * than the exit — so it ends with the period already paid for.
     */
    public function testAnAnytimeOfferEndsWithThePaidPeriodDespiteTheCommitment(): void
    {
        $this->subscribeTo($this->anytimeOffer);

        $cancellation = $this->cancellationIn($this->decode($this->cancel()));

        self::assertSame('AT_PERIOD_END', $cancellation['effect'] ?? null);
        self::assertSame('cancel.anytime_under_commitment', $cancellation['rule_id'] ?? null);
    }

    /**
     * A fixed term outlives the commitment inside it, and the offer says the
     * subscription runs to that term.
     */
    public function testATermOfferDefersToItsTerm(): void
    {
        $this->subscribeTo($this->termOffer);

        $cancellation = $this->cancellationIn($this->decode($this->cancel()));

        self::assertSame('AT_TERM', $cancellation['effect'] ?? null);
        self::assertSame('cancel.deferred_to_term', $cancellation['rule_id'] ?? null);
    }

    /**
     * The diagnostic and the act share a code path, so the schedule endpoint
     * cannot promise something the cancel endpoint then refuses — the same
     * reason /tax/calculate is not a second implementation of invoicing.
     */
    public function testTheScheduleEndpointAgreesWithWhatCancellingWouldDo(): void
    {
        $this->subscribeTo($this->lockedOffer);

        $view = $this->decode($this->request('GET', '/api/v1/subscription/schedule', $this->headers()));
        $predicted = $view['if_cancelled_now'] ?? null;
        self::assertIsArray($predicted);

        // Asking must not change anything.
        self::assertSame(0, $this->rowsMatching(
            'SELECT count(*) FROM subscriptions WHERE cancel_at_period_end',
        ));

        $actual = $this->cancellationIn($this->decode($this->cancel()));

        self::assertSame($predicted['rule_id'] ?? null, $actual['rule_id'] ?? null);
        self::assertSame($predicted['effect'] ?? null, $actual['effect'] ?? null);
    }

    // --- What leaving early costs -------------------------------------------

    /**
     * The scenario this milestone exists for: an offer that sells the exit
     * bills what remains, as a real document.
     *
     * Eleven months, not twelve: the month already paid for is not owed
     * twice.
     */
    public function testBuyingOutACommitmentRaisesAnInvoiceForWhatRemains(): void
    {
        $this->subscribeTo($this->buyoutOffer);

        $body = $this->decode($this->cancel(immediately: true));
        $cancellation = $this->cancellationIn($body);

        self::assertSame('CANCELLED', $body['status'] ?? null);
        self::assertSame('cancel.early_termination', $cancellation['rule_id'] ?? null);
        self::assertSame(11, $cancellation['chargeable_months'] ?? null);

        $invoiceId = $cancellation['charge_invoice_id'] ?? null;
        self::assertIsString($invoiceId);

        // 11 × €29.00 net, and the VAT that follows from it — a document, not
        // a number on a subscription row.
        self::assertSame(31_900, $this->amountOf($invoiceId, 'net_minor_units'));
        self::assertSame(
            1,
            $this->rowsMatching(
                'SELECT count(*) FROM invoice_lines WHERE quantity = 11',
            ),
        );

        // §25.3: the sale produced a fiscal fact, on the same transaction.
        self::assertSame(1, $this->rowsMatching('SELECT count(*) FROM vat_transactions'));
    }

    /**
     * The same immediate exit, sold as free, produces no document at all.
     * A €0 invoice would be a permanent, ungapped record of no transaction.
     */
    public function testAFreeEarlyExitRaisesNothing(): void
    {
        $this->subscribeTo($this->freeExitOffer);

        $cancellation = $this->cancellationIn($this->decode($this->cancel(immediately: true)));

        self::assertSame('cancel.early_termination', $cancellation['rule_id'] ?? null);
        self::assertSame(0, $cancellation['chargeable_months'] ?? null);
        self::assertArrayHasKey('charge_invoice_id', $cancellation);
        self::assertNull($cancellation['charge_invoice_id']);
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    /**
     * The release and its charge are one fact. With no billing profile the
     * invoice cannot be raised, and the subscription must still be running
     * afterwards — a customer let out for free because the paperwork failed
     * is the outcome the shared transaction exists to prevent.
     */
    public function testABuyOutThatCannotBeInvoicedDoesNotReleaseTheSubscription(): void
    {
        $this->subscribeTo($this->buyoutOffer);
        $this->connection->executeStatement('DELETE FROM billing_profiles');

        $refused = $this->cancel(immediately: true);

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('BILLING_PROFILE_REQUIRED', $this->errorOf($refused)['code'] ?? null);

        // Still running, still committed, and no half-written document.
        self::assertSame(
            1,
            $this->rowsMatching("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"),
        );
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM invoices'));
    }

    // --- Who a subscription entitles ----------------------------------------

    /**
     * A seat is addressed to a person. The colleague who does not hold one
     * gets nothing from it, which is the difference between a seat and a
     * tenant subscription.
     */
    public function testASeatEntitlesItsHolderAndNobodyElse(): void
    {
        $this->subscribeTo($this->anytimeOffer, seat: true);

        $mine = $this->decode($this->request('GET', '/api/v1/me/entitlements', $this->headers()));
        self::assertSame(['advanced_3d', 'max_projects'], $mine['capabilities'] ?? null);

        $theirs = $this->decode(
            $this->request('GET', '/api/v1/me/entitlements', $this->headers('bob-token')),
        );
        self::assertSame([], $theirs['capabilities'] ?? null);
    }

    /**
     * The two gates have to answer the same question about the same person.
     *
     * Capability resolution runs with the caller, so a seat's quota feature
     * reaches $context->capabilities. If the quota check then asks the
     * tenant-wide question it finds nothing and refuses with "you need this
     * entitlement" — the one the caller is holding. Capability yes, quota no,
     * for the same feature and the same person.
     */
    public function testASeatsQuotaIsFoundForItsHolder(): void
    {
        $this->subscribeTo($this->anytimeOffer, seat: true);

        $quotas = new QuotaPolicy(
            new PostgresEntitlementRepository($this->connection),
            new UsageMeter(['max_projects' => new FixedUsage(1)]),
        );

        // Named: the seat is theirs and its limit of 50 applies.
        $quotas->assertMayConsume($this->tenant, $this->product, 'max_projects', $this->user);

        // Unnamed, the tenant-wide question — and the tenant bought nothing,
        // so the honest answer there is still no.
        $refused = null;

        try {
            $quotas->assertMayConsume($this->tenant, $this->product, 'max_projects');
        } catch (HttpException $error) {
            $refused = $error;
        }

        self::assertNotNull($refused);
        self::assertSame('ENTITLEMENT_REQUIRED', $refused->errorCode());
    }

    /**
     * A seat that can be taken out and never given up is not a subscription,
     * it is a trap. Cancelling names the scope with a flag, never an id:
     * whose seat it could be is already settled by the context.
     */
    public function testASeatIsCancelledOnItsOwn(): void
    {
        $this->subscribeTo($this->anytimeOffer);
        $this->subscribeTo($this->anytimeOffer, seat: true);

        $body = $this->decode($this->cancelSeat());

        $subscriber = $body['subscriber'] ?? null;
        self::assertIsArray($subscriber);
        self::assertSame('USER', $subscriber['kind'] ?? null);
        self::assertSame('AT_PERIOD_END', $this->cancellationIn($body)['effect'] ?? null);

        // The tenant's subscription is untouched: two scopes, two decisions.
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM subscriptions
              WHERE subscriber_kind = 'TENANT' AND NOT cancel_at_period_end",
        ));
    }

    public function testCancellingASeatNobodyHoldsSaysSo(): void
    {
        $this->subscribeTo($this->anytimeOffer);

        $refused = $this->cancelSeat();

        self::assertSame(404, $refused->getStatusCode());
        self::assertSame('NO_SEAT', $this->errorOf($refused)['code'] ?? null);
    }

    /**
     * The schedule endpoint answers about the same scope the cancel endpoint
     * would act on, or the prediction is about somebody else's subscription.
     */
    public function testTheScheduleEndpointAnswersAboutTheSeatToo(): void
    {
        $this->subscribeTo($this->anytimeOffer, seat: true);

        $view = $this->decode(
            $this->request('GET', '/api/v1/subscription/schedule?seat=1', $this->headers()),
        );

        $subscription = $view['subscription'] ?? null;
        self::assertIsArray($subscription);

        $subscriber = $subscription['subscriber'] ?? null;
        self::assertIsArray($subscriber);
        self::assertSame('USER', $subscriber['kind'] ?? null);
    }

    /**
     * A cancellation nobody can be held to is not much of a record (§30).
     *
     * Written on the cancellation's own transaction, so it cannot be the
     * half that got lost — and carrying the decision itself, because "which
     * rule released this customer, and what did it cost them?" is exactly
     * what a dispute asks.
     */
    public function testCancellingIsRecordedInTheAuditTrail(): void
    {
        $this->subscribeTo($this->buyoutOffer);
        $this->cancel(immediately: true);

        $entry = $this->connection->fetchAssociative(
            "SELECT action, subject_type, tenant_id, user_id, request_id, detail
               FROM audit_log WHERE action = 'subscription.cancelled'",
        );

        self::assertIsArray($entry);
        self::assertSame('subscription', $entry['subject_type'] ?? null);
        self::assertSame($this->tenant, $entry['tenant_id'] ?? null);
        self::assertSame($this->user, $entry['user_id'] ?? null);

        // The middleware gives every request an id; the point is that it
        // reached the trail, not what it was.
        self::assertNotNull($entry['request_id'] ?? null);

        $detail = $entry['detail'] ?? null;
        self::assertIsString($detail);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($detail, true);
        self::assertSame('cancel.early_termination', $decoded['rule_id'] ?? null);
        self::assertSame(11, $decoded['chargeable_months'] ?? null);
        self::assertIsString($decoded['charge_invoice_id'] ?? null);
    }

    /**
     * The release and its record are one fact. When the charge cannot be
     * raised the whole transaction goes, and a trail claiming a cancellation
     * that never happened would be worse than no trail.
     */
    public function testNothingIsRecordedWhenTheCancellationRollsBack(): void
    {
        $this->subscribeTo($this->buyoutOffer);
        $this->connection->executeStatement('DELETE FROM billing_profiles');

        self::assertSame(409, $this->cancel(immediately: true)->getStatusCode());

        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM audit_log WHERE action = 'subscription.cancelled'",
        ));
    }

    // --- Helpers ------------------------------------------------------------

    private function cancel(bool $immediately = false): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/cancel',
            $this->headers(),
            $this->json(['immediately' => $immediately]),
        );
    }

    private function cancelSeat(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription/cancel',
            $this->headers(),
            $this->json(['seat' => true]),
        );
    }

    private function subscribeTo(string $offerId, bool $seat = false): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/subscription',
            $this->headers(),
            $this->json(['offer_id' => $offerId, 'seat' => $seat]),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function cancellationIn(array $body): array
    {
        $cancellation = $body['cancellation'] ?? null;

        self::assertIsArray($cancellation);

        /** @var array<string, mixed> $cancellation */
        return $cancellation;
    }

    private function amountOf(string $invoiceId, string $column): int
    {
        $amount = $this->connection->fetchOne(
            sprintf('SELECT %s FROM invoices WHERE id = :id', $column),
            ['id' => $invoiceId],
        );

        self::assertIsNumeric($amount);

        return (int) $amount;
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function membership(string $userId): TenantMembership
    {
        return new TenantMembership(
            $this->tenant,
            $userId,
            $this->product,
            ['TENANT_ADMIN'],
            ['subscription.read', 'subscription.manage', 'entitlements.read', 'catalog.read'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token = 'alice-token'): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
    }

    private function seedBillingIdentity(): void
    {
        $supplier = json_encode([
            'legal_name' => 'Atlas SAS',
            'vat_number' => 'FR12345678901',
            'registration_number' => '123 456 789 00012',
            'address_line1' => '1 rue de la Paix',
            'postal_code' => '75002',
            'city' => 'Paris',
            'country_code' => 'FR',
        ]);

        self::assertIsString($supplier);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value) VALUES
                    (:product, 'billing_supplier', CAST(:supplier AS jsonb)),
                    (:product, 'tax', CAST('{"country": "FR", "oss_registered": true}' AS jsonb))
                SQL,
            ['product' => $this->product, 'supplier' => $supplier],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, address_line1, postal_code, city, country_code)
                VALUES (:tenant, 'Acme SARL', '12 avenue des Champs', '75008', 'Paris', 'FR')
                SQL,
            ['tenant' => $this->tenant],
        );
    }

    private function seedCatalogue(): void
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $advanced = $this->id(
            'INSERT INTO features (code, name, kind, unit)'
            . " VALUES ('advanced_3d', 'advanced_3d', 'BOOLEAN', NULL) RETURNING id",
        );

        // A quota, so the seat scenarios can ask the question a boolean
        // capability never does: is the *limit* found for the person holding
        // the seat, or only for the tenant?
        $projects = $this->id(
            'INSERT INTO features (code, name, kind, unit)'
            . " VALUES ('max_projects', 'max_projects', 'QUOTA', 'projects') RETURNING id",
        );

        // Twelve months of commitment on every one of them. What differs is
        // only what the offer says about leaving, which is exactly the axis
        // §13.1 makes an option rather than a policy.
        $only = [[$advanced, null]];
        $withQuota = [[$advanced, null], [$projects, 50]];

        $this->lockedOffer = $this->offer($plan, 'locked', $only, null, 12, 'AT_COMMITMENT_END', 'FORBIDDEN');
        $this->buyoutOffer = $this->offer($plan, 'buyout', $only, null, 12, 'AT_COMMITMENT_END', 'CHARGE_REMAINING');
        $this->freeExitOffer = $this->offer($plan, 'freeexit', $only, null, 12, 'AT_COMMITMENT_END', 'FREE');
        $this->anytimeOffer = $this->offer($plan, 'anytime', $withQuota, null, 12, 'ANYTIME', 'FORBIDDEN');
        $this->termOffer = $this->offer($plan, 'term', $only, 24, 12, 'AT_TERM', 'FORBIDDEN');
    }

    /**
     * @param list<array{string, int|null}> $grants
     */
    private function offer(
        string $planId,
        string $code,
        array $grants,
        ?int $termMonths,
        int $commitmentMonths,
        string $policy,
        string $earlyTermination,
    ): string {
        $offerId = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . ' VALUES (:product, :plan, :code, :code) RETURNING id',
            ['product' => $this->product, 'plan' => $planId, 'code' => $code],
        );

        $versionId = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency,
                     valid_from, term_months, commitment_months, cancellation_policy,
                     renewal, early_termination)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day',
                        :term, :commitment, :policy, 'AUTO_RENEW', :earlyTermination)
                RETURNING id
                SQL,
            [
                'offer' => $offerId,
                'term' => $termMonths,
                'commitment' => $commitmentMonths,
                'policy' => $policy,
                'earlyTermination' => $earlyTermination,
            ],
        );

        foreach ($grants as [$featureId, $limit]) {
            $this->connection->executeStatement(
                'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)'
                . ' VALUES (:version, :feature, :limit)',
                ['version' => $versionId, 'feature' => $featureId, 'limit' => $limit],
            );
        }

        // Built the way the product builds one: DRAFT, then its grants, then
        // published. A version's grants are frozen once it leaves DRAFT
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
