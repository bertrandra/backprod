<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Job\Domain\Job;
use App\Payment\Infrastructure\Stripe\StripeSignature;
use App\Privacy\Service\Erasure;
use App\Tenant\Service\MemberAdministration;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\RecordingWebhookTransport;
use App\Tests\Support\TestDatabase;
use App\Webhook\Domain\ProductEvents;
use App\Webhook\Domain\WebhookEndpoints;
use App\Webhook\Domain\WebhookSignature;
use App\Webhook\Domain\WebhookTransport;
use App\Webhook\Infrastructure\PostgresWebhookEndpoints;
use App\Webhook\Service\DeliverWebhooks;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The platform tells a product what happened (ADR-051 §5), against the real
 * database and the real job, with only the wire replaced.
 *
 * Four claims. The address and the secret are set from the console, and the
 * secret is shown once. What the platform records — a subscription event, a
 * member, an assignment, an erasure — becomes one signed delivery the
 * product can verify with the mirror of the platform's own Stripe check.
 * A product that does not answer is retried on the schedule and then
 * parked, visible and retriable. And a rotation signs with both secrets
 * for a day, so the product swaps its copy without a gap.
 */
#[CoversNothing]
final class WebhookDeliveryTest extends DatabaseApiTestCase
{
    private const KEY = 'a-test-key-of-at-least-thirty-two-characters';

    private string $admin = '';
    private string $support = '';
    private string $plan = '';
    private string $atlas = '';
    private string $acme = '';
    private string $globex = '';
    private string $ada = '';
    private RecordingWebhookTransport $wire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = $this->id("INSERT INTO products (code, name, active) VALUES ('plan', 'Plan', true) RETURNING id");
        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        $this->admin = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->support = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");
        $this->ada = $this->id("INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-ada', 'ada@acme.test', 'Ada') RETURNING id");

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        TestDatabase::assignProduct($this->connection, $this->acme, $this->plan);
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);

        $this->wire = new RecordingWebhookTransport();

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
            WebhookTransport::class => $this->wire,
            // The sealing key is read from the environment once per container;
            // the test's own is given here, the way the signing secret is.
            WebhookEndpoints::class => new PostgresWebhookEndpoints($this->connection, self::KEY),
        ]);
    }

    public function testTheAddressAndTheSecretAreSetFromTheConsoleAndTheSecretIsShownOnce(): void
    {
        // https only, and nothing that smuggles a credential in the address.
        self::assertSame(400, $this->patch(['webhook_url' => 'http://plan.example.test/hook'])->getStatusCode());
        self::assertSame(400, $this->patch(['webhook_url' => 'https://user:pw@plan.example.test/hook'])->getStatusCode());
        self::assertSame(403, $this->patch(['webhook_url' => 'https://plan.example.test/hook'], 'sam-token')->getStatusCode());

        $set = $this->patch(['webhook_url' => 'https://plan.example.test/api/v1/plan/platform-events']);
        self::assertSame(200, $set->getStatusCode());
        $product = $this->decode($set)['product'] ?? null;
        self::assertIsArray($product);
        self::assertSame('https://plan.example.test/api/v1/plan/platform-events', $product['webhook_url'] ?? null);
        self::assertArrayHasKey('webhook_secret_issued_at', $product);
        self::assertNull($product['webhook_secret_issued_at']);

        self::assertSame(403, $this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-secret', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/v1/staff/products/00000000-0000-4000-8000-000000000000/webhook-secret', $this->staff())->getStatusCode());

        $issued = $this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-secret', $this->staff());
        self::assertSame(201, $issued->getStatusCode());
        $body = $this->decode($issued);
        $secret = $body['secret'] ?? null;
        self::assertIsString($secret);
        self::assertMatchesRegularExpression('/^bwh_[A-Za-z0-9_-]{43}$/', $secret);
        $after = $body['product'] ?? null;
        self::assertIsArray($after);
        self::assertIsString($after['webhook_secret_issued_at'] ?? null);

        // Sealed at rest: the row holds neither the secret nor anything
        // that contains it, and the list never says it again.
        $stored = $this->connection->fetchOne('SELECT webhook_secret FROM products WHERE id = :id', ['id' => $this->plan]);
        self::assertIsString($stored);
        self::assertStringNotContainsString(substr($secret, 4), $stored);
        $listed = json_encode($this->decode($this->request('GET', '/api/v1/staff/products', $this->staff())));
        self::assertIsString($listed);
        self::assertStringNotContainsString($secret, $listed);
        self::assertStringNotContainsString('"secret"', $listed);

        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'SET_WEBHOOK_URL'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'ISSUE_WEBHOOK_SECRET'"));
    }

    public function testASubscriptionEventIsCollectedSignedAndDeliveredOnce(): void
    {
        $secret = $this->connect();
        $subscription = $this->subscription();

        // The collector's cursor sits an hour back, and the event is old
        // enough to be past the late-commit margin.
        $this->connection->executeStatement(
            "INSERT INTO webhook_cursors (name, position) VALUES ('subscription_events', now() - interval '1 hour') ON CONFLICT (name) DO UPDATE SET position = EXCLUDED.position",
        );
        $event = $this->id(
            "INSERT INTO subscription_events (subscription_id, type, occurred_at) VALUES (:s, 'ACTIVATED', now() - interval '2 minutes') RETURNING id",
            ['s' => $subscription],
        );

        $outcome = $this->pass();
        self::assertSame(['collected' => 1, 'delivered' => 1, 'failed' => 0, 'parked' => 0], $outcome);
        self::assertCount(1, $this->wire->sent);

        $sent = $this->wire->sent[0];
        self::assertSame('https://plan.example.test/api/v1/plan/platform-events', $sent['url']);
        $payload = json_decode($sent['body'], true);
        self::assertIsArray($payload);
        self::assertSame($event, $payload['event_id'] ?? null);
        self::assertSame('subscription.started', $payload['type'] ?? null);
        self::assertSame('plan', $payload['product'] ?? null);
        self::assertSame($this->acme, $payload['tenant_id'] ?? null);
        self::assertIsString($payload['occurred_at'] ?? null);
        $detail = $payload['subscription'] ?? null;
        self::assertIsArray($detail);
        self::assertSame($subscription, $detail['id'] ?? null);
        self::assertSame('ACTIVE', $detail['status'] ?? null);
        self::assertSame('pro-monthly', $detail['offer'] ?? null);
        self::assertSame(['kind' => 'TENANT', 'user_id' => null], $detail['subscriber'] ?? null);
        // Never money, never a credential.
        self::assertStringNotContainsString('price', $sent['body']);
        self::assertStringNotContainsString('bwh_', $sent['body']);

        // The product verifies it exactly the way the platform verifies
        // Stripe's: same header shape, same HMAC, same replay window.
        self::assertSame('subscription.started', $sent['headers']['X-Backprod-Event'] ?? null);
        self::assertSame('application/json', $sent['headers']['Content-Type'] ?? null);
        StripeSignature::verify($sent['body'], $sent['headers'][WebhookSignature::HEADER] ?? null, $secret, time());

        $row = $this->connection->fetchAssociative('SELECT delivered_at, last_status, attempt, parked_at FROM webhook_deliveries WHERE event_id = :e', ['e' => $event]);
        self::assertIsArray($row);
        self::assertNotNull($row['delivered_at']);
        self::assertSame(200, $row['last_status']);
        self::assertSame(1, $row['attempt']);

        // Again: the cursor moved, the row is delivered, nothing is sent twice.
        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 0], $this->pass());
        self::assertCount(1, $this->wire->sent);

        // A second product's subscription is that product's news, and a
        // product with no address hears nothing: Atlas has none.
        $this->connection->executeStatement(
            "INSERT INTO subscriptions (tenant_id, product_id, offer_version_id, status, current_period_start, current_period_end) SELECT :t, :p, v.id, 'ACTIVE', now(), now() + interval '30 days' FROM offer_versions v LIMIT 1",
            ['t' => $this->acme, 'p' => $this->atlas],
        );
        $this->connection->executeStatement(
            "INSERT INTO subscription_events (subscription_id, type, occurred_at) SELECT id, 'ACTIVATED', now() - interval '2 minutes' FROM subscriptions WHERE product_id = :p",
            ['p' => $this->atlas],
        );
        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 0], $this->pass());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM webhook_deliveries'));
    }

    public function testMembersAssignmentsAndErasuresAreToldToTheProduct(): void
    {
        $this->connect();

        // Assigning a product to a tenant, from the console.
        self::assertSame(200, $this->request('PUT', '/api/v1/staff/tenants/' . $this->globex . '/products/' . $this->plan, $this->staff())->getStatusCode());
        // A member joining Acme: told to Plan, which Acme holds and which
        // has an address — not to Atlas, which has none.
        $members = $this->container()->get(MemberAdministration::class);
        self::assertInstanceOf(MemberAdministration::class, $members);
        $members->add($this->acme, $this->atlas, 'ada@acme.test', ['USER']);
        $members->remove($this->acme, $this->atlas, $this->ada);
        // Taking the product back.
        self::assertSame(200, $this->request('DELETE', '/api/v1/staff/tenants/' . $this->globex . '/products/' . $this->plan, $this->staff())->getStatusCode());
        // A person forgotten (§26): every product that can be told, is.
        $erasure = $this->container()->get(Erasure::class);
        self::assertInstanceOf(Erasure::class, $erasure);
        $erasure->erase($this->ada, $this->admin, null);

        $rows = $this->connection->fetchAllAssociative('SELECT event_type, tenant_id, payload FROM webhook_deliveries WHERE product_id = :p ORDER BY created_at, event_type', ['p' => $this->plan]);
        self::assertSame(
            ['tenant.product.assigned', 'member.added', 'member.removed', 'tenant.product.unassigned', 'user.erased'],
            array_map(static fn (array $row): mixed => $row['event_type'], $rows),
        );
        self::assertSame($this->globex, $rows[0]['tenant_id']);
        self::assertSame($this->acme, $rows[1]['tenant_id']);
        self::assertNull($rows[4]['tenant_id']);
        $erasedPayload = $rows[4]['payload'];
        self::assertIsString($erasedPayload);
        self::assertSame(['user' => ['id' => $this->ada]], json_decode($erasedPayload, true));
        // The id, and nothing that was just erased.
        self::assertStringNotContainsString('ada@', $erasedPayload);
        self::assertStringNotContainsString('Ada', $erasedPayload);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE product_id = :p', ['p' => $this->atlas]));

        // All five go out in one pass, each signed.
        self::assertSame(['collected' => 0, 'delivered' => 5, 'failed' => 0, 'parked' => 0], $this->pass());
        self::assertCount(5, $this->wire->sent);
        foreach ($this->wire->sent as $sent) {
            self::assertStringStartsWith('t=', $sent['headers'][WebhookSignature::HEADER] ?? '');
        }
    }

    public function testAFailedDeliveryBacksOffThenParksAndCanBeRetried(): void
    {
        $this->connect();
        $this->publish('member.added', $this->acme);
        $this->wire->answer(503);

        // Five failures on the schedule, none of them parked yet.
        $expected = [60, 600, 3_600, 21_600, 86_400];

        foreach ($expected as $attempt => $delay) {
            self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 1, 'parked' => 0], $this->pass(), 'attempt ' . ($attempt + 1));
            $row = $this->delivery();
            self::assertSame($attempt + 1, $row['attempt']);
            self::assertNull($row['parked_at']);
            self::assertSame(503, $row['last_status']);
            self::assertSame('HTTP_503', $row['last_error']);
            $wait = $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM (next_attempt_at - now())) FROM webhook_deliveries');
            self::assertIsNumeric($wait);
            self::assertEqualsWithDelta($delay, (float) $wait, 30, 'backoff after attempt ' . ($attempt + 1));
            // Not due yet, so a pass in between sends nothing.
            self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 0], $this->pass());
            $this->connection->executeStatement('UPDATE webhook_deliveries SET next_attempt_at = now()');
        }

        // The sixth is the last: parked, with its answer, visible from the console.
        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 1], $this->pass());
        $row = $this->delivery();
        self::assertNotNull($row['parked_at']);
        self::assertSame(6, $row['attempt']);
        $id = $row['id'];
        self::assertIsString($id);

        $listed = $this->decode($this->request('GET', '/api/v1/staff/products/' . $this->plan . '/webhook-deliveries', $this->staff()))['deliveries'] ?? null;
        self::assertIsArray($listed);
        self::assertCount(1, $listed);
        $first = $listed[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('member.added', $first['event_type'] ?? null);
        self::assertSame(503, $first['last_status'] ?? null);
        self::assertIsString($first['parked_at'] ?? null);
        self::assertArrayNotHasKey('payload', $first);

        // Put back, and delivered once the product is up again.
        self::assertSame(403, $this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-deliveries/' . $id . '/retry', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/v1/staff/products/' . $this->atlas . '/webhook-deliveries/' . $id . '/retry', $this->staff())->getStatusCode());
        $retried = $this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-deliveries/' . $id . '/retry', $this->staff());
        self::assertSame(200, $retried->getStatusCode());
        $delivery = $this->decode($retried)['delivery'] ?? null;
        self::assertIsArray($delivery);
        self::assertArrayHasKey('parked_at', $delivery);
        self::assertNull($delivery['parked_at']);
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'RETRY_WEBHOOK'"));

        $this->wire->answer(204);
        self::assertSame(['collected' => 0, 'delivered' => 1, 'failed' => 0, 'parked' => 0], $this->pass());
        self::assertSame(204, $this->delivery()['last_status']);

        // A 400 is a product that read the delivery and refused it: parked
        // at once, because sending it again would be refused again.
        $this->publish('member.removed', $this->acme);
        $this->wire->answer(400);
        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 1], $this->pass());
        self::assertSame('HTTP_400', $this->connection->fetchOne("SELECT last_error FROM webhook_deliveries WHERE event_type = 'member.removed'"));

        // An unreachable host is an answer too, and the class of it is what
        // the console reads.
        $this->publish('member.added', $this->globex);
        $this->wire->answer(null, 'TIMEOUT');
        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 1, 'parked' => 0], $this->pass());
        self::assertSame('TIMEOUT', $this->connection->fetchOne('SELECT last_error FROM webhook_deliveries WHERE tenant_id = :t', ['t' => $this->globex]));
    }

    public function testAnAddressWithoutASecretIsParkedRatherThanSentUnsigned(): void
    {
        self::assertSame(200, $this->patch(['webhook_url' => 'https://plan.example.test/hook'])->getStatusCode());
        $this->publish('member.added', $this->acme);

        self::assertSame(['collected' => 0, 'delivered' => 0, 'failed' => 0, 'parked' => 1], $this->pass());
        self::assertSame([], $this->wire->sent);
        self::assertSame('NO_ENDPOINT', $this->delivery()['last_error']);
    }

    public function testARotationSignsWithBothSecretsForADay(): void
    {
        $old = $this->connect();
        $new = $this->issueSecret();
        self::assertNotSame($old, $new);

        $this->publish('member.added', $this->acme);
        self::assertSame(['collected' => 0, 'delivered' => 1, 'failed' => 0, 'parked' => 0], $this->pass());
        $header = $this->wire->sent[0]['headers'][WebhookSignature::HEADER] ?? null;
        self::assertIsString($header);
        self::assertSame(2, substr_count($header, 'v1='));
        // Either copy verifies: the product swaps at its own pace.
        StripeSignature::verify($this->wire->sent[0]['body'], $header, $old, time());
        StripeSignature::verify($this->wire->sent[0]['body'], $header, $new, time());

        // The window closed: the old one no longer signs.
        $this->connection->executeStatement("UPDATE products SET webhook_previous_until = now() - interval '1 second' WHERE id = :id", ['id' => $this->plan]);
        $this->publish('member.removed', $this->acme);
        self::assertSame(['collected' => 0, 'delivered' => 1, 'failed' => 0, 'parked' => 0], $this->pass());
        $header = $this->wire->sent[1]['headers'][WebhookSignature::HEADER] ?? null;
        self::assertIsString($header);
        self::assertSame(1, substr_count($header, 'v1='));
        StripeSignature::verify($this->wire->sent[1]['body'], $header, $new, time());
        $this->expectExceptionMessageMatches('/.*/');
        StripeSignature::verify($this->wire->sent[1]['body'], $header, $old, time());
    }

    public function testWithoutASealingKeyNoSecretCanBeIssued(): void
    {
        $this->override([WebhookEndpoints::class => new PostgresWebhookEndpoints($this->connection, '')]);

        $refused = $this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-secret', $this->staff());
        self::assertSame(503, $refused->getStatusCode());
        self::assertSame('WEBHOOKS_NOT_CONFIGURED', $this->errorOf($refused)['code'] ?? null);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function staff(): array
    {
        return ['Authorization' => 'Bearer ola-token'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(array $body, string $token = 'ola-token'): ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/staff/products/' . $this->plan, ['Authorization' => 'Bearer ' . $token], $this->json($body));
    }

    /** Plan with an address and a secret, as the operator leaves it. Returns the secret. */
    private function connect(): string
    {
        self::assertSame(200, $this->patch(['webhook_url' => 'https://plan.example.test/api/v1/plan/platform-events'])->getStatusCode());

        return $this->issueSecret();
    }

    private function issueSecret(): string
    {
        $secret = $this->decode($this->request('POST', '/api/v1/staff/products/' . $this->plan . '/webhook-secret', $this->staff()))['secret'] ?? null;
        self::assertIsString($secret);

        return $secret;
    }

    private function publish(string $type, string $tenantId): void
    {
        $events = $this->container()->get(ProductEvents::class);
        self::assertInstanceOf(ProductEvents::class, $events);
        $events->publish($type, $this->plan, $tenantId, ['member' => ['user_id' => $this->ada]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pass(): array
    {
        $handler = $this->container()->get(DeliverWebhooks::class);
        self::assertInstanceOf(DeliverWebhooks::class, $handler);
        $now = new DateTimeImmutable();

        return $handler->handle(new Job('00000000-0000-0000-0000-000000000000', DeliverWebhooks::TYPE, 'QUEUED', null, null, [], null, null, 0, 0, 5, $now, null, null, null, null, $now));
    }

    /**
     * @return array<string, mixed>
     */
    private function delivery(): array
    {
        $row = $this->connection->fetchAssociative('SELECT id, attempt, parked_at, delivered_at, last_status, last_error FROM webhook_deliveries ORDER BY created_at LIMIT 1');
        self::assertIsArray($row);

        return $row;
    }

    /** Acme subscribed to Plan's monthly offer. */
    private function subscription(): string
    {
        $planRow = $this->id("INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 1) RETURNING id", ['p' => $this->plan]);
        $offer = $this->id("INSERT INTO offers (product_id, plan_id, code, name, publicly_listed) VALUES (:p, :plan, 'pro-monthly', 'Pro monthly', true) RETURNING id", ['p' => $this->plan, 'plan' => $planRow]);
        $version = $this->id(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:o, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day') RETURNING id",
            ['o' => $offer],
        );

        return $this->id(
            "INSERT INTO subscriptions (tenant_id, product_id, offer_version_id, status, current_period_start, current_period_end) VALUES (:t, :p, :v, 'ACTIVE', now(), now() + interval '30 days') RETURNING id",
            ['t' => $this->acme, 'p' => $this->plan, 'v' => $version],
        );
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
            ['user' => $userId, 'role' => $role],
        );
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
