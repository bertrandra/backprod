<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Demo\Domain\DemoWorld;
use App\Demo\Service\DemoSeeder;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The demonstration world, rebuilt from the console — through the real
 * pipeline and the real database.
 *
 * **Not doubled.** The claim is that the reset empties every business table
 * and seeds the world the seeder defines, verified; that it refuses while a
 * product that is not the demo's exists; and that the caller has deleted
 * themselves. All three are what the database holds afterwards, which a fake
 * seeder could only repeat from its fixture.
 */
#[CoversNothing]
final class DemoResetTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Two staff, appointed by SQL the way the older staff tests do: the
        // reset is what deletes them, which is part of what is proven.
        $this->admin = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->support = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),
        ]);
    }

    public function testResettingSeedsTheWorldAndVerifiesIt(): void
    {
        $this->seedDemoWithLeftovers();

        $response = $this->reset('ola-token');

        self::assertSame(200, $response->getStatusCode());
        $world = $this->decode($response)['world'] ?? null;
        self::assertIsArray($world);

        // What the answer says: who to sign in as, and with what.
        self::assertSame(DemoWorld::PASSWORD, $world['password'] ?? null);
        // In the order the world gives them, not the alphabet's (2026-09-23):
        // Plan first, because it is the product deployed beside the platform
        // and the first option is what somebody with no default lands on.
        self::assertSame(['plan', 'atlas', 'boreas', 'ceres', 'delos'], array_column($this->listIn($world, 'products'), 'code'));
        self::assertSame(array_keys(DemoWorld::PRODUCTS), array_column($this->listIn($world, 'products'), 'code'));
        self::assertSame(
            [
                'backprod@raillard.org',
                // acme-user4 holds the Lecture seat (2026-09-23). An ordinary
                // member with an ordinary role: what makes them a reader is the
                // seat, not who they are.
                'acme-admin@raillard.org', 'acme-user1@raillard.org', 'acme-user2@raillard.org', 'acme-user4@raillard.org',
                'globex-admin@raillard.org', 'globex-user1@raillard.org', 'globex-user2@raillard.org',
                'initech-admin@raillard.org', 'initech-user1@raillard.org',
            ],
            array_column($this->listIn($world, 'people'), 'email'),
        );
        // Three issuers, three series (2026-09-25). Acme sold three seats and
        // numbers them 1, 2, 3; Globex and Initech sold one each and both
        // start at 1. Under the platform-wide counter this read 1 to 5, so
        // Initech's only invoice announced four documents it never wrote and
        // Acme's own series was missing the two that went elsewhere.
        self::assertSame(['2026-000001', '2026-000002', '2026-000003', '2026-000001', '2026-000001'], $world['invoices'] ?? null);
        // And the series is the issuer's, not the tenant scope's: every
        // document here was raised by the organisation it is scoped to,
        // because every one of them is a seat it sold.
        self::assertSame(
            0,
            $this->rowCount('SELECT count(*) FROM invoices WHERE issuer_tenant_id IS DISTINCT FROM tenant_id'),
        );

        // What the database holds: the world and nothing else.
        self::assertSame(5, $this->rowCount('SELECT count(*) FROM products'));
        self::assertSame(count(DemoWorld::PEOPLE), $this->rowCount('SELECT count(*) FROM users'));
        // Every subscription is a seat (2026-09-25): one person bought it,
        // for themselves — or, since 2026-09-27, took the free period, which
        // is a seat too and the only one nobody paid for. The two counts are
        // deliberately the same number asked two ways — the second is what
        // would notice an organisation subscription creeping back in.
        $held = count(DemoWorld::SEATS) + count(DemoWorld::FREEMIUM);

        self::assertSame(
            $held,
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"),
        );
        self::assertSame(
            $held,
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE subscriber_kind = 'USER' AND status = 'ACTIVE'"),
        );
        // And exactly one of them is free, raising no document at all (§6.3):
        // the invoices counted above are the sold seats' and nobody else's.
        self::assertSame(
            count(DemoWorld::FREEMIUM),
            $this->rowCount('SELECT count(*) FROM subscriptions WHERE is_freemium'),
        );
        // And each was sold: an order, fulfilled, and an invoice that was
        // paid. A seeder that went back to activating them directly would
        // leave these at zero.
        self::assertSame(
            count(DemoWorld::SEATS),
            $this->rowCount("SELECT count(*) FROM orders WHERE status = 'COMPLETED'"),
        );
        self::assertSame(
            count(DemoWorld::SEATS),
            $this->rowCount("SELECT count(*) FROM invoices WHERE status = 'PAID'"),
        );
        // Down to the project (2026-09-22): made through the workspace, so
        // each was counted against a quota the offer actually grants and
        // stored under a schema version the product declared.
        self::assertSame(count(DemoWorld::PROJECTS), $this->rowCount('SELECT count(*) FROM projects'));
        self::assertSame(
            count(array_filter(DemoWorld::PROJECTS, static fn (array $draft): bool => $draft['product'] === 'plan')),
            $this->rowCount("SELECT count(*) FROM projects p JOIN products pr ON pr.id = p.product_id WHERE pr.code = 'plan'"),
        );
        self::assertSame('https://plan.raillard.org', $this->connection->fetchOne("SELECT app_url FROM products WHERE code = 'plan'"));
        // Where a member's screens open when no address says (2026-09-23):
        // the product beside the platform, for everybody who holds it — and
        // nothing at all for staff, who hold no membership to choose from.
        self::assertSame(
            count(DemoWorld::PEOPLE) - 1,
            $this->rowCount("SELECT count(*) FROM users u JOIN products p ON p.id = u.default_product_id WHERE p.code = 'plan'"),
        );
        self::assertSame(
            1,
            $this->rowCount('SELECT count(*) FROM users WHERE default_product_id IS NULL'),
        );
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM tenants WHERE slug = 'leftover'"));
        self::assertSame(0, $this->rowCount('SELECT count(*) FROM users WHERE id = :id', ['id' => $this->admin]));
        // Reference data is not business data.
        self::assertSame(6, $this->rowCount('SELECT count(*) FROM roles') + $this->rowCount('SELECT count(*) FROM platform_roles'));
    }

    /**
     * The demonstration collects money, and the screen that shows it can
     * read what was collected (2026-09-26).
     *
     * The world seeded five invoices and no payment at all, so `/payments`
     * was permanently empty in the one place the platform is shown to
     * people — a screen that is always empty reads as a feature that does
     * not work. Both halves are proven here, because a fixture that
     * satisfies the database and not the contract is a fixture that will
     * embarrass somebody: the rows, and then the same rows read back
     * through the API by the person whose seat they belong to, carrying the
     * invoice number and the customer `CollectedPayment` promises.
     */
    public function testTheWorldHasMoneyInItAndThePaymentsScreenCanReadIt(): void
    {
        self::assertSame(200, $this->reset('ola-token')->getStatusCode());

        // Two that went through and two that did not: the screen renders a
        // failure differently and offers to try again, so a world with only
        // successes demonstrates half of it.
        self::assertSame(2, $this->rowCount("SELECT count(*) FROM payments WHERE status = 'SUCCEEDED'"));
        self::assertSame(2, $this->rowCount("SELECT count(*) FROM payments WHERE status = 'FAILED'"));
        self::assertSame(
            2,
            $this->rowCount("SELECT count(*) FROM payments WHERE status = 'FAILED' AND failure_code IS NOT NULL AND failure_reason IS NOT NULL"),
        );
        // Across two organisations, so the console's cross-tenant views have
        // something to show as well.
        self::assertSame(2, $this->rowCount('SELECT count(DISTINCT tenant_id) FROM payments'));
        // The one somebody would notice on stage: money collected that the
        // document never asked for.
        self::assertSame(0, $this->rowCount(
            <<<'SQL'
            SELECT count(*) FROM payments p JOIN invoices i ON i.id = p.invoice_id
            WHERE p.amount_minor_units <> i.gross_minor_units OR p.currency <> i.currency
            SQL,
        ));
        // A retry needs a debt. A failed attempt on an invoice that has
        // since been paid cannot be retried at all, so the world holds one
        // order still owing — fulfilled, invoiced, declined, no seat.
        self::assertSame(1, $this->rowCount(
            <<<'SQL'
            SELECT count(*) FROM payments p JOIN invoices i ON i.id = p.invoice_id
            WHERE p.status = 'FAILED' AND i.status = 'ISSUED'
            SQL,
        ));
        self::assertSame(
            count(DemoWorld::SEATS) + count(DemoWorld::FREEMIUM),
            $this->rowCount("SELECT count(*) FROM subscriptions WHERE status = 'ACTIVE'"),
            'a declined order must not have started a seat',
        );

        $this->signInAsTheDemoPeople();

        // The holder of Acme's Plan seat: the attempt the card refused and
        // the one that went through, both on the invoice that bought it.
        $theirs = $this->paymentsOf('acme-user1', 'plan');

        self::assertCount(2, $theirs);
        self::assertSame(['FAILED', 'SUCCEEDED'], $this->sortedColumn($theirs, 'status'));

        foreach ($theirs as $payment) {
            // What `CollectedPayment` promises: the document's own legal
            // number and the customer it was raised to. A seat is the
            // organisation selling to one of its own people, so the customer
            // is a person.
            self::assertSame('2026-000001', $payment['invoice_number'] ?? null);
            self::assertSame(DemoWorld::PEOPLE['acme-user1']['name'], $payment['customer_name'] ?? null);
            self::assertSame(DemoWorld::email('acme-user1'), $payment['customer_email'] ?? null);

            // And the amount the contract carries is the document's, to the
            // minor unit — asserted against the invoice rather than a
            // literal, because what is being proven is where it comes from.
            $invoiceId = $payment['invoice_id'] ?? null;
            self::assertIsString($invoiceId);
            $amount = $payment['amount'] ?? null;
            self::assertIsArray($amount);
            self::assertSame(
                $this->rowCount('SELECT gross_minor_units FROM invoices WHERE id = :id', ['id' => $invoiceId]),
                $amount['minor_units'] ?? null,
            );
        }

        // The colleague whose card was refused: their debt, still owed, and
        // a reason a person can read beside a code support can quote.
        $refused = $this->paymentsOf('globex-user2', 'atlas');

        self::assertCount(1, $refused);
        self::assertSame('FAILED', $refused[0]['status'] ?? null);
        self::assertSame('card_declined', $refused[0]['failure_code'] ?? null);
        self::assertIsString($refused[0]['failure_reason'] ?? null);
        self::assertSame(DemoWorld::PEOPLE['globex-user2']['name'], $refused[0]['customer_name'] ?? null);
        // Globex's second document, in Globex's own series (2026-09-25).
        self::assertSame('2026-000002', $refused[0]['invoice_number'] ?? null);

        // And what they are not shown: the seat their colleague paid for is
        // on another product, and somebody else's payment is not theirs to
        // see (2026-09-18).
        self::assertCount(1, $this->paymentsOf('globex-user1', 'boreas'));
        self::assertCount(0, $this->paymentsOf('globex-user2', 'boreas'));
    }

    public function testTheCallerIsSignedOutByTheirOwnReset(): void
    {
        $this->seedDemoWithLeftovers();

        self::assertSame(200, $this->reset('ola-token')->getStatusCode());

        // Their row is gone, so their token names nobody. Whether that reads
        // as "not signed in" or "not staff" depends on which link of the
        // context chain notices first; what matters is that it is refused,
        // and the page knows to show the sign-in form.
        $status = $this->request('GET', '/api/v1/staff/me', ['Authorization' => 'Bearer ola-token'])->getStatusCode();
        self::assertContains($status, [401, 403]);
    }

    public function testResettingIsRefusedWhileARealProductExists(): void
    {
        $this->seedDemoWithLeftovers();
        $this->id("INSERT INTO products (code, name, active) VALUES ('real-thing', 'Real Thing', true) RETURNING id");

        $response = $this->reset('ola-token');

        self::assertSame(409, $response->getStatusCode());
        $error = $this->decode($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(DemoSeeder::NOT_A_DEMO_DEPLOYMENT, $error['code'] ?? null);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame(['real-thing'], $details['products'] ?? null);
        // Nothing was touched: the real product, and the caller, are still there.
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM products WHERE code = 'real-thing'"));
        self::assertSame(1, $this->rowCount('SELECT count(*) FROM users WHERE id = :id', ['id' => $this->admin]));
    }

    public function testAnEmptyDatabaseCanBeReset(): void
    {
        // No product at all is not "a product that is not the demo's".
        $response = $this->reset('ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $this->rowCount('SELECT count(*) FROM products'));
    }

    public function testOnlyThePlatformAdministratorMayReset(): void
    {
        $this->seedDemoWithLeftovers();

        self::assertSame(403, $this->reset('sam-token')->getStatusCode());
        self::assertSame(401, $this->request('POST', '/api/v1/staff/demo/reset')->getStatusCode());
        // Refused before anything happened.
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM tenants WHERE slug = 'leftover'"));
    }

    // --- Fixtures ----------------------------------------------------------------

    /**
     * The demo, plus what a walk through it leaves behind: a tenant that is
     * not the demo's on one of the demo's products. Not a foreign *product* —
     * the rule is about products — so the reset must take it.
     */
    private function seedDemoWithLeftovers(): void
    {
        $atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $leftover = $this->id("INSERT INTO tenants (name, slug) VALUES ('Leftover Ltd', 'leftover') RETURNING id");
        $this->connection->executeStatement(
            'INSERT INTO tenant_products (tenant_id, product_id) VALUES (:tenant, :product)',
            ['tenant' => $leftover, 'product' => $atlas],
        );
    }

    /**
     * A token per seeded person, named after them.
     *
     * The subjects are only knowable after the reset: it deletes every user
     * and writes them again, and what a token this platform issues carries
     * is `local:<id>`. So the fake provider is replaced here rather than in
     * `setUp`, which is also what signs the platform administrator out —
     * their row is gone, so their token names nobody.
     */
    private function signInAsTheDemoPeople(): void
    {
        $subjects = [];

        foreach (array_keys(DemoWorld::PEOPLE) as $who) {
            $id = $this->connection->fetchOne(
                'SELECT id FROM users WHERE email = :email',
                ['email' => DemoWorld::email($who)],
            );

            self::assertIsString($id, "{$who} was not seeded");

            $subjects[$who] = 'local:' . $id;
        }

        $this->override([AuthProvider::class => new FakeAuthProvider($subjects)]);
    }

    /**
     * What the Payments screen would read for one person on one product.
     *
     * Through the contract, not the table: a USER sees their own documents
     * and nothing else, and what each row carries is the presenter's answer
     * rather than a column.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsOf(string $who, string $product): array
    {
        $response = $this->request(
            'GET',
            '/api/v1/billing/payments',
            ['Authorization' => 'Bearer ' . $who, 'X-Product' => $product],
        );

        self::assertSame(200, $response->getStatusCode(), "{$who} could not read payments on {$product}");

        return $this->listIn($this->decode($response), 'payments');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function sortedColumn(array $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            self::assertIsString($value);
            $values[] = $value;
        }

        sort($values);

        return $values;
    }

    private function reset(string $token): ResponseInterface
    {
        return $this->request('POST', '/api/v1/staff/demo/reset', ['Authorization' => 'Bearer ' . $token]);
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
    }

    /**
     * @param array<mixed, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    private function listIn(array $source, string $key): array
    {
        $values = $source[$key] ?? null;
        self::assertIsArray($values);

        $items = [];

        foreach ($values as $value) {
            self::assertIsArray($value);
            /** @var array<string, mixed> $value */
            $items[] = $value;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
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
}
