<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * A member sees what concerns them; the administrator sees the
 * organisation (2026-09-18).
 *
 * Since self-service lets anybody into the organisation at the root as a
 * USER, the reads a USER holds — invoices, payments, orders — would show a
 * stranger every document the organisation ever raised. They now show the
 * person their own: the orders that bought their seat, the invoices those
 * raised, the payments on them. The wider view is `billing.manage`, which
 * is the administrator's and never a USER's ({@see UserRoleMatrixTest}).
 *
 * Somebody else's document is a 404 for a member, not a 403: the id is not
 * the person's to know about, and a refusal that differs from "no such
 * thing" would confirm it exists.
 */
#[CoversNothing]
final class OwnDocumentsTest extends DatabaseApiTestCase
{
    private const SECRET = 'own-documents-test-secret';

    private string $product = '';
    private string $tenant = '';
    private string $admin = '';
    private string $member = '';
    private string $offer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-alice', 'alice@example.test') RETURNING id",
        );
        $this->member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bob', 'bob@example.test') RETURNING id",
        );

        $this->configure();
        $this->seedCatalogue();

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['alice-token' => 'sub-alice', 'bob-token' => 'sub-bob']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->admin,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['billing.read', 'billing.manage', 'billing.pay', 'payments.read', 'sales.read', 'sales.manage', 'subscription.read'],
                ),
                // What the migrations give a USER, without `billing.manage`.
                new TenantMembership(
                    $this->tenant,
                    $this->member,
                    $this->product,
                    ['USER'],
                    ['billing.read', 'billing.pay', 'payments.read', 'sales.read', 'subscription.read'],
                ),
            ]),
        ]);

        $this->saveProfile();
    }

    public function testAMemberSeesTheirOwnSeatsDocumentsAndTheAdministratorSeesEveryone(): void
    {
        // The organisation subscribes, by its administrator; the member buys
        // a seat of their own. Two orders, two invoices, two payments.
        $company = $this->sessionOf($this->open('alice-token'));
        $this->pay($company, 'evt_company');
        $seat = $this->sessionOf($this->open('bob-token', seat: true));
        $this->pay($seat, 'evt_seat');

        // The administrator: the organisation's view.
        self::assertCount(2, $this->idsOf($this->get('alice-token', '/api/v1/billing/invoices'), 'invoices'));
        self::assertCount(2, $this->idsOf($this->get('alice-token', '/api/v1/billing/payments'), 'payments'));
        self::assertCount(2, $this->idsOf($this->get('alice-token', '/api/v1/sales/orders'), 'orders'));

        // The member: their own, and nothing else.
        self::assertSame([$seat['invoice_id']], $this->idsOf($this->get('bob-token', '/api/v1/billing/invoices'), 'invoices'));
        self::assertSame([$seat['payment_id']], $this->idsOf($this->get('bob-token', '/api/v1/billing/payments'), 'payments'));
        self::assertSame([$seat['id']], $this->idsOf($this->get('bob-token', '/api/v1/sales/orders'), 'orders'));
    }

    /**
     * Whose each payment is (2026-09-26).
     *
     * `billing.manage` shows an administrator every payment the organisation
     * has, and until this none of the rows said which colleague each one
     * belonged to: twelve identical amounts were twelve identical rows. The
     * facts are the invoice's — its number, and the customer it was raised to
     * — read from the snapshot, so they say who that was when the document
     * was raised (§25) rather than who they are now.
     */
    public function testEveryPaymentNamesTheDocumentItCollectsAndWhoItWasRaisedTo(): void
    {
        $company = $this->sessionOf($this->open('alice-token'));
        $this->pay($company, 'evt_company');
        $seat = $this->sessionOf($this->open('bob-token', seat: true));
        $this->pay($seat, 'evt_seat');

        $payments = $this->rowsOf($this->get('alice-token', '/api/v1/billing/payments'), 'payments');

        self::assertCount(2, $payments);

        foreach ($payments as $payment) {
            self::assertArrayHasKey('invoice_number', $payment);
            self::assertArrayHasKey('customer_name', $payment);
            self::assertArrayHasKey('customer_email', $payment);

            // A legal number, because these invoices were issued. Not a
            // placeholder anywhere: a draft's is null and stays null.
            self::assertIsString($payment['invoice_number']);
            self::assertNotSame('', $payment['invoice_number']);
        }

        $names = [];

        foreach ($payments as $payment) {
            self::assertIsString($payment['customer_name']);
            $names[] = $payment['customer_name'];
        }

        // Two colleagues, two different answers — which is the whole point: a
        // list where every row said the same thing would not have been worth
        // the read.
        self::assertCount(2, array_unique($names));

        // And each one is the **snapshot's**, not a live row's. Compared
        // against what the document actually holds rather than against a
        // literal, because the rule being tested is *where the name comes
        // from*: resolving a current name onto a past document would show a
        // customer who has since renamed themselves under a name their paper
        // copy does not carry.
        foreach ($payments as $payment) {
            self::assertIsString($payment['invoice_id']);

            self::assertSame(
                $this->connection->fetchOne(
                    "SELECT customer_snapshot->>'legal_name' FROM invoices WHERE id = :id",
                    ['id' => $payment['invoice_id']],
                ),
                $payment['customer_name'],
            );
        }
    }

    public function testAPaymentOfItsOwnCarriesTheSameFacts(): void
    {
        $seat = $this->sessionOf($this->open('bob-token', seat: true));
        $this->pay($seat, 'evt_seat');

        self::assertIsString($seat['payment_id']);

        $payment = $this->decode($this->get('bob-token', '/api/v1/billing/payments/' . $seat['payment_id']));

        // The next question after "did it go through?" is "whose was this?",
        // and the one-payment read has to answer it too.
        self::assertSame(
            $this->connection->fetchOne(
                "SELECT customer_snapshot->>'billing_email' FROM invoices WHERE id = :id",
                ['id' => $seat['invoice_id']],
            ),
            $payment['customer_email'] ?? null,
        );
        self::assertIsString($payment['invoice_number'] ?? null);
    }

    public function testSomebodyElsesDocumentIsNotThereForAMember(): void
    {
        $company = $this->sessionOf($this->open('alice-token'));
        $this->pay($company, 'evt_company');

        self::assertIsString($company['invoice_id']);
        self::assertIsString($company['payment_id']);
        self::assertIsString($company['id']);

        foreach ([
            '/api/v1/billing/invoices/' . $company['invoice_id'],
            '/api/v1/billing/invoices/' . $company['invoice_id'] . '/pdf',
            '/api/v1/billing/payments/' . $company['payment_id'],
            '/api/v1/sales/orders/' . $company['id'],
            '/api/v1/checkout/sessions/' . $company['id'],
        ] as $path) {
            self::assertSame(404, $this->get('bob-token', $path)->getStatusCode(), $path . ' is not the member\'s');
            self::assertSame(200, $this->get('alice-token', $path)->getStatusCode(), $path . ' is the administrator\'s to see');
        }
    }

    public function testAMemberCannotGiveUpAColleaguesPurchase(): void
    {
        $company = $this->sessionOf($this->open('alice-token'));
        self::assertIsString($company['id']);

        $response = $this->request('POST', '/api/v1/checkout/sessions/' . $company['id'] . '/cancel', $this->headers('bob-token'));
        self::assertSame(404, $response->getStatusCode());

        // Untouched: still waiting for the administrator's money.
        self::assertSame('AWAITING_PAYMENT', $this->sessionOf($this->get('alice-token', '/api/v1/checkout/sessions/' . $company['id']))['status'] ?? null);

        // Their own, they may.
        $seat = $this->sessionOf($this->open('bob-token', seat: true));
        self::assertIsString($seat['id']);
        $given = $this->request('POST', '/api/v1/checkout/sessions/' . $seat['id'] . '/cancel', $this->headers('bob-token'));
        self::assertSame(200, $given->getStatusCode());
        self::assertSame('CANCELLED', $this->sessionOf($given)['status'] ?? null);
    }

    public function testAMembersOwnDocumentsReadBack(): void
    {
        $seat = $this->sessionOf($this->open('bob-token', seat: true));
        $this->pay($seat, 'evt_seat');

        self::assertIsString($seat['invoice_id']);
        self::assertIsString($seat['payment_id']);
        self::assertIsString($seat['id']);

        self::assertSame(200, $this->get('bob-token', '/api/v1/billing/invoices/' . $seat['invoice_id'])->getStatusCode());
        self::assertSame(200, $this->get('bob-token', '/api/v1/billing/payments/' . $seat['payment_id'])->getStatusCode());
        self::assertSame(200, $this->get('bob-token', '/api/v1/sales/orders/' . $seat['id'])->getStatusCode());
        self::assertSame('COMPLETED', $this->sessionOf($this->get('bob-token', '/api/v1/checkout/sessions/' . $seat['id']))['status'] ?? null);
    }

    // --- Helpers ------------------------------------------------------------

    private function open(string $token, bool $seat = false): ResponseInterface
    {
        $response = $this->request(
            'POST',
            '/api/v1/checkout/sessions',
            $this->headers($token),
            $this->json($seat ? ['offer_id' => $this->offer, 'seat' => true] : ['offer_id' => $this->offer]),
        );
        self::assertSame(201, $response->getStatusCode());

        return $response;
    }

    private function get(string $token, string $path): ResponseInterface
    {
        return $this->request('GET', $path, $this->headers($token));
    }

    /**
     * The rows on a page, for the assertions that are about what is on them
     * rather than about which ones are there.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(ResponseInterface $response, string $key): array
    {
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->decode($response)[$key] ?? null;

        self::assertIsArray($rows);

        $page = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $page[] = $row;
        }

        return $page;
    }

    /**
     * The ids on a page, with the count agreeing with them.
     *
     * @return list<mixed>
     */
    private function idsOf(ResponseInterface $response, string $key): array
    {
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $rows = $body[$key] ?? null;
        self::assertIsArray($rows);
        self::assertSame(count($rows), $body['total'] ?? null, 'the count agrees with the page');

        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $ids[] = $row['id'] ?? null;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function pay(array $session, string $eventId): void
    {
        $reference = $this->connection->fetchOne(
            'SELECT provider_payment_id FROM payments WHERE id = :id',
            ['id' => $session['payment_id'] ?? null],
        );
        self::assertIsString($reference);

        $body = $this->json(['id' => $eventId, 'type' => 'payment.succeeded', 'payment_id' => $reference]);

        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payments/stub',
            [StubPaymentProvider::SIGNATURE_HEADER => (new StubPaymentProvider(self::SECRET))->sign($body)],
            $body,
        );
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionOf(ResponseInterface $response): array
    {
        $session = $this->decode($response)['session'] ?? null;

        self::assertIsArray($session);

        /** @var array<string, mixed> $session */
        return $session;
    }

    private function saveProfile(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/billing/profile',
            $this->headers('alice-token'),
            $this->json(['legal_name' => 'Acme SARL', 'country_code' => 'FR', 'city' => 'Paris']),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function configure(): void
    {
        $supplier = json_encode([
            'legal_name' => 'Atlas SAS',
            'vat_number' => 'FR12345678901',
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
    }

    private function seedCatalogue(): void
    {
        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:product, 'PRO', 'Pro', 20) RETURNING id",
            ['product' => $this->product],
        );

        $this->offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name)'
            . " VALUES (:product, :plan, 'pro', 'Atlas Pro') RETURNING id",
            ['product' => $this->product, 'plan' => $plan],
        );

        $this->id(
            'INSERT INTO offer_versions'
            . ' (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)'
            . " VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')"
            . ' RETURNING id',
            ['offer' => $this->offer],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
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
