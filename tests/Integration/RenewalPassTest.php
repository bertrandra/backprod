<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Domain\RenewalPolicy;
use App\Commerce\Domain\Subscription;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\Commerce\Service\Subscriptions;
use App\Job\Domain\Job;
use App\Job\Service\RenewSubscriptions;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Shared\Exceptions\ConflictException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A paid period rolling into the next one, and being billed for it (ADR-068).
 *
 * Against the real database and the real billing chain, because the document is
 * half the point: `renew()` moved the period and the entitlements forward and
 * raised nothing at all, which was invisible only while its one caller was a
 * service method with no endpoint and no job. A double here would prove the
 * double writes nothing.
 *
 * The first case is the one that protects every existing deployment: a product
 * that has said nothing renews nothing. Everything this file adds is off until
 * an operator chooses it, because what silence would cost is a customer billed
 * for a period nobody decided to sell them.
 */
#[CoversNothing]
final class RenewalPassTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $monthlyOffer = '';

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
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@acme.test') RETURNING id",
        );

        $this->seedBillingIdentity();
        $this->monthlyOffer = $this->seedMonthlyOffer();

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
                    ['subscription.read', 'subscription.manage'],
                ),
            ]),
        ]);
    }

    // --- the default, which is nothing ---------------------------------------

    public function testAProductThatHasSaidNothingRenewsNothing(): void
    {
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $outcome = $this->pass();

        // The whole of what an upgrading deployment gets: the subscription is
        // read, recognised as due, and left alone because nobody decided to
        // sell another period of it.
        self::assertSame(0, $outcome['renewed']);
        self::assertSame(1, $outcome['switched_off']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testAnUnreadableSettingIsTreatedAsNoSettingAtAll(): void
    {
        // A string "true" is what a hand-edited document produces. Reading it as
        // truthy would turn a typo into money.
        $this->chooseRenewal('{"automatic": "true"}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        self::assertSame(1, $this->pass()['switched_off']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testALeadOutsideTheBoundsIsTreatedAsNoSettingAtAll(): void
    {
        $this->chooseRenewal(sprintf(
            '{"automatic": true, "lead_days": %d}',
            RenewalPolicy::LONGEST_LEAD + 1,
        ));
        $this->subscribe();
        $this->periodEndsIn('1 day');

        self::assertSame(1, $this->pass()['switched_off']);
    }

    // --- switched on ----------------------------------------------------------

    public function testItRollsThePeriodAndRaisesARealInvoiceForIt(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $before = $this->subscribe();
        $this->periodEndsIn('1 day');

        $endedAt = $this->currentPeriodEnd();

        self::assertSame(1, $this->pass()['renewed']);

        // The period moved on from where it ended, not from now: renewing a day
        // early must not shorten what the customer paid for.
        $after = $this->currentPeriodEnd();
        self::assertGreaterThan($endedAt, $after);
        self::assertSame(
            $endedAt->format('Y-m-d'),
            $this->currentPeriodStart()->format('Y-m-d'),
        );

        // A real document, numbered, for the period that is starting. Not a
        // placeholder and not a €0 line: this is what the customer is being
        // asked to pay.
        $invoice = $this->onlyInvoice();

        self::assertNotNull($invoice['number']);
        self::assertSame($endedAt->format('Y-m-d'), $invoice['period_start']);
        self::assertSame($after->format('Y-m-d'), $invoice['period_end']);
        self::assertSame($before->id, $invoice['subscription_id']);

        // The offer's own price, and taxed: a domestic sale on €29.00. Asserted
        // rather than assumed, because a renewal priced from the offer *as it
        // stands today* rather than from the version the customer holds would
        // look identical until somebody repriced the catalogue (§13.1).
        $net = self::minorUnits($invoice, 'net_minor_units');
        $vat = self::minorUnits($invoice, 'vat_minor_units');

        self::assertSame(2900, $net);
        self::assertGreaterThan(0, $vat);
        self::assertSame($net + $vat, self::minorUnits($invoice, 'gross_minor_units'));
    }

    public function testASecondPassBillsNothingMore(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $this->pass();
        $again = $this->pass();

        // The period is a month out now, so it is not even read. The guard on
        // the update exists for passes that overlap rather than follow, and this
        // is the ordinary case it must not disturb.
        self::assertSame(0, $again['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testItDoesNotRenewBeforeItsLead(): void
    {
        $this->chooseRenewal('{"automatic": true, "lead_days": 2}');
        $this->subscribe();
        $this->periodEndsIn('9 days');

        $outcome = $this->pass();

        // Read, because the query bounds by the longest lead any product could
        // choose, and declined, because this product chose two days.
        self::assertSame(0, $outcome['renewed']);
        self::assertSame(1, $outcome['not_yet']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testTwoPassesHoldingTheSamePeriodBillItOnce(): void
    {
        // The guard the ordinary cases cannot reach. Two overlapping passes both
        // read a subscription, the first renews it, and the second is still
        // holding the period that has gone. Without the condition on the update
        // the second would roll the period a second time and bill a second
        // invoice — one period, two documents, and a customer charged twice.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        // The subscription as the losing pass still holds it: read *before* the
        // winning pass runs, so its period end is the one that is about to move.
        // Read through the service, which is how any caller gets one.
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        $held = $subscriptions->current($this->tenant, $this->product, $this->user);
        self::assertNotNull($held);

        $repository = new PostgresSubscriptionRepository($this->connection);

        self::assertSame(1, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());

        $billed = 0;

        try {
            $repository->renew(
                $held,
                new DateTimeImmutable('+2 months'),
                function () use (&$billed): string {
                    $billed++;

                    return 'never';
                },
            );

            self::fail('A second renewal of the same period was allowed.');
        } catch (ConflictException $refusal) {
            self::assertSame('RENEWAL_ALREADY_APPLIED', $refusal->errorCode());
        }

        // Nothing was billed, and the period did not move again: the update
        // refuses before the closure runs, and the transaction takes the rest
        // with it.
        self::assertSame(0, $billed);
        self::assertSame(1, $this->invoiceCount());
    }

    // --- what it must not touch ----------------------------------------------

    public function testALapsedSubscriptionIsNotEvenRead(): void
    {
        // The point of renewing *before* the period ends. A lapsed subscription
        // is `sweep.subscriptions`'s, and a pass that took it would be racing
        // that sweep over whether somebody keeps their subscription.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('-1 day');

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(0, $outcome['switched_off']);
        self::assertSame(0, $outcome['not_yet']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testASubscriptionCancellingAtPeriodEndIsNotRenewed(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $this->connection->executeStatement(
            'UPDATE subscriptions SET cancel_at_period_end = true, cancel_effective_at = current_period_end',
        );

        // Renewing here would extend a subscription past the date the customer
        // was given and bill them for a period they cancelled.
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testASubscriptionInArrearsIsNotRenewed(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();

        // One period billed, so there is a real debt for the arrears to name:
        // `subscriptions_arrears_name_their_debt` requires the date and the
        // invoice together, because arrears with no document are arrears nobody
        // can be asked to settle.
        $this->periodEndsIn('1 day');
        self::assertSame(1, $this->pass()['renewed']);

        $this->periodEndsIn('1 day');
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE subscriptions
                   SET status = 'PAST_DUE',
                       past_due_since = now(),
                       past_due_invoice_id = (SELECT id FROM invoices LIMIT 1)
                SQL,
        );

        // It owes for the period it already had and the dunning pass is chasing
        // it. A second invoice against a suspended service compounds a debt
        // somebody is already being asked for.
        self::assertSame(0, $this->pass()['renewed']);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testAPeriodThatWouldRunPastTheTermIsRefusedRatherThanShortened(): void
    {
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        // A term ten days out, with a monthly period: the next period would end
        // three weeks after the contract. Billing a whole month for ten days
        // overcharges; capping the end would sell service past the term. So it
        // is refused, and the subscription reaches its term.
        $this->connection->executeStatement(
            "UPDATE subscriptions SET term_ends_at = current_period_end + INTERVAL '10 days'",
        );

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(['RENEWAL_WOULD_PASS_TERM' => 1], $outcome['refused']);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testThePeriodEndingAtTheTermIsNotRead(): void
    {
        // The last period of a contract. What follows it is tacit renewal of a
        // commitment, which is a decision somebody takes and not something cron
        // does — so the query does not offer it.
        $this->chooseRenewal('{"automatic": true}');
        $this->subscribe();
        $this->periodEndsIn('1 day');

        $this->connection->executeStatement(
            'UPDATE subscriptions SET term_ends_at = current_period_end',
        );

        $outcome = $this->pass();

        self::assertSame(0, $outcome['renewed']);
        self::assertSame(0, $outcome['switched_off']);
        self::assertSame(0, $outcome['not_yet']);
    }

    // --- helpers -------------------------------------------------------------

    /** @return array<string, mixed> */
    private function pass(): array
    {
        $handler = $this->container()->get(RenewSubscriptions::class);
        self::assertInstanceOf(RenewSubscriptions::class, $handler);

        return $handler->handle($this->job());
    }

    /**
     * The handler takes a Job and reads nothing from it: the work is whatever is
     * due, not whatever the payload says. A bare queued job is a faithful
     * stand-in rather than a shortcut.
     */
    private function job(): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            RenewSubscriptions::TYPE,
            'QUEUED',
            null,
            null,
            [],
            null,
            null,
            0,
            0,
            3,
            $now,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    private function subscribe(): Subscription
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        return $subscriptions->subscribe(
            $this->tenant,
            $this->product,
            $this->monthlyOffer,
            $this->user,
            $this->user,
        );
    }

    private function chooseRenewal(string $document): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'renewal', CAST(:value AS jsonb))
                SQL,
            ['product' => $this->product, 'value' => $document],
        );
    }

    /**
     * Moves the paid period so it ends `$interval` from now.
     *
     * The start moves with it, a month earlier, because
     * `subscriptions_period_ordered` is a CHECK and a period that ends before it
     * begins is not a state this platform can hold — which is the right answer
     * and makes the helper say so.
     */
    private function periodEndsIn(string $interval): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE subscriptions
                       SET current_period_end = now() + INTERVAL '%1$s',
                           current_period_start = now() + INTERVAL '%1$s' - INTERVAL '1 month'
                    SQL,
                $interval,
            ),
        );
    }

    private function currentPeriodEnd(): DateTimeImmutable
    {
        return $this->moment('current_period_end');
    }

    private function currentPeriodStart(): DateTimeImmutable
    {
        return $this->moment('current_period_start');
    }

    private function moment(string $column): DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT ' . $column . ' FROM subscriptions LIMIT 1');
        self::assertIsString($value);

        return new DateTimeImmutable($value);
    }

    /** @param array<string, mixed> $row */
    private static function minorUnits(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function invoiceCount(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM invoices');

        return is_numeric($count) ? (int) $count : -1;
    }

    /** @return array<string, mixed> */
    private function onlyInvoice(): array
    {
        self::assertSame(1, $this->invoiceCount());

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT number, subscription_id,
                       net_minor_units, vat_minor_units, gross_minor_units,
                       to_char(period_start, 'YYYY-MM-DD') AS period_start,
                       to_char(period_end, 'YYYY-MM-DD') AS period_end
                  FROM invoices LIMIT 1
                SQL,
        );

        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
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

    /**
     * Open-ended, monthly, €29. Built the way the product builds one: DRAFT,
     * then its grants, then active — a version's grants are frozen once it
     * leaves DRAFT (ADR-033).
     */
    private function seedMonthlyOffer(): string
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank)'
            . " VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $feature = $this->id(
            'INSERT INTO features (code, name, kind, unit)'
            . " VALUES ('max_projects', 'max_projects', 'QUOTA', 'projects') RETURNING id",
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'pro-monthly', 'Pro monthly') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency,
                     valid_from, term_months, commitment_months, cancellation_policy,
                     renewal, early_termination)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day',
                        NULL, 0, 'ANYTIME', 'AUTO_RENEW', 'FREE')
                RETURNING id
                SQL,
            ['offer' => $offer],
        );

        $this->connection->executeStatement(
            'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)'
            . ' VALUES (:version, :feature, 3)',
            ['version' => $version, 'feature' => $feature],
        );

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        return $offer;
    }
}
