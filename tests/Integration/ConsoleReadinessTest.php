<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The map of the maze.
 *
 * The test that matters is the last one, and it is unlike the others in this
 * suite: it does not check a list of facts, it checks that **the list is a
 * path**. At every point it does only what `next` names, and after nine console
 * calls the product is sellable — never once having been told to do something
 * it was not yet allowed to do.
 *
 * That is the claim worth defending. A checklist of independent boxes would
 * pass every other assertion here and still let somebody start at the end.
 */
#[CoversNothing]
final class ConsoleReadinessTest extends DatabaseApiTestCase
{
    private const SECRET = 'readiness-test-secret';

    /**
     * @var array<string, string>
     */
    private const ISSUER = [
        'legal_name' => 'Atlas SAS',
        'country_code' => 'FR',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->appoint($admin, 'PLATFORM_ADMIN');
        $this->appoint($support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),
            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),
        ]);

        $created = $this->request(
            'POST',
            '/api/v1/staff/products',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'atlas', 'name' => 'Atlas']),
        );

        self::assertSame(201, $created->getStatusCode());
    }

    // --- What a fresh product looks like -------------------------------------

    public function testAFreshProductIsNotSellableAndSaysWhatToDoFirst(): void
    {
        $readiness = $this->decode($this->readiness());

        self::assertFalse($readiness['sellable'] ?? null);
        // Not "configure something": the first thing that is actually blocking,
        // which on a product created moments ago is its billing identity.
        self::assertSame('billing_identity', $readiness['next'] ?? null);
    }

    public function testTheChainIsReturnedInDependencyOrder(): void
    {
        // The order is the contract. A step that cannot be started until an
        // earlier one is done sits after it — which is what makes following the
        // list top to bottom safe.
        self::assertSame(
            [
                'product',
                'billing_identity',
                'tax',
                'plans',
                'features',
                'offers',
                'published',
                'advertised',
                'payments',
            ],
            array_column($this->steps(), 'key'),
        );
    }

    public function testAProductJustCreatedHasItsFirstStepDone(): void
    {
        self::assertTrue($this->step('product')['done'] ?? null);
        self::assertSame('atlas', $this->detailOf('product')['code'] ?? null);
    }

    public function testTheStepsThatAreNotBlockingSaySo(): void
    {
        // A supplier selling at home invoices correctly without a stated tax
        // position, and an offer granting only access to the product is a
        // legitimate offer. Listing them as required would send somebody to do
        // work the platform does not need.
        self::assertFalse($this->step('tax')['blocking'] ?? null);
        self::assertFalse($this->step('features')['blocking'] ?? null);

        foreach (['product', 'billing_identity', 'plans', 'offers', 'published', 'advertised', 'payments'] as $key) {
            self::assertTrue($this->step($key)['blocking'] ?? null, $key . ' must block a sale');
        }
    }

    public function testTheAuditNamesWhatIsMissingAndNotOnlyThatSomethingIs(): void
    {
        // The same field names the invoice path refuses over, from the same
        // rule — so what this lists is exactly what a checkout is failing on.
        self::assertSame(
            ['legal_name', 'country_code'],
            $this->detailOf('billing_identity')['missing'] ?? null,
        );
    }

    public function testEmptyCollectionsAreCountedRatherThanLeftUnsaid(): void
    {
        self::assertSame(0, $this->detailOf('plans')['count'] ?? null);
        self::assertSame(0, $this->detailOf('offers')['count'] ?? null);
    }

    // --- Who may ask ---------------------------------------------------------

    public function testOnlyProductAdministratorsMayReadTheChain(): void
    {
        // It names what a product is missing, which is commercial information
        // about the platform's own readiness to sell.
        self::assertSame(403, $this->readiness('sam-token', 403)->getStatusCode());
    }

    public function testAnUnknownProductIsANotFound(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/staff/readiness?product=nope',
            ['Authorization' => 'Bearer ola-token'],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PRODUCT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    // --- The facts come from the enforcing code ------------------------------

    public function testARetiredProductIsNotADoneFirstStep(): void
    {
        $id = $this->connection->fetchOne("SELECT id FROM products WHERE code = 'atlas'");
        self::assertIsString($id);

        self::assertSame(200, $this->request(
            'PATCH',
            '/api/v1/staff/products/' . $id,
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['active' => false]),
        )->getStatusCode());

        // Retiring closes every door into a product. A chain that called that
        // "done" would describe a product nobody can reach.
        self::assertFalse($this->step('product')['done'] ?? null);
        self::assertSame('product', $this->decode($this->readiness())['next'] ?? null);
    }

    public function testADeploymentWithNoPaymentProviderSaysSo(): void
    {
        $this->override([PaymentProviders::class => new PaymentProviders([])]);

        $payments = $this->step('payments');

        self::assertFalse($payments['done'] ?? null);
        self::assertTrue($payments['blocking'] ?? null);
    }

    public function testAPublishedVersionOutsideItsWindowIsNotOnSale(): void
    {
        $this->configureBilling();
        $planId = $this->planId();

        // A version whose selling window has not opened yet. Its status will be
        // ACTIVE and nothing will sell it — which is why this step asks the
        // clock rather than reading the status column.
        $offerId = $this->offerId($this->createOffer($planId, [
            'valid_from' => '2099-01-01T00:00:00Z',
        ]));

        self::assertSame(200, $this->publish($offerId)->getStatusCode());

        $status = $this->connection->fetchOne(
            'SELECT status FROM offer_versions WHERE offer_id = :id',
            ['id' => $offerId],
        );

        self::assertSame('ACTIVE', $status);
        self::assertFalse($this->step('published')['done'] ?? null, 'a future window is not on sale');
    }

    // --- The whole point -----------------------------------------------------

    public function testFollowingNextFromEndToEndNeverAsksForSomethingImpossible(): void
    {
        $planId = null;
        $offerId = null;
        $done = [];

        // At most a dozen turns; the chain is nine steps and each is one call.
        for ($turn = 0; $turn < 12; ++$turn) {
            $readiness = $this->decode($this->readiness());

            if (($readiness['sellable'] ?? null) === true) {
                break;
            }

            $next = $readiness['next'] ?? null;
            self::assertIsString($next);
            self::assertNotContains($next, $done, 'the chain asked for ' . $next . ' twice');
            $done[] = $next;

            // Only ever what `next` names — never a step further down, and never
            // one the chain has not reached. If any of these refused, the order
            // would be wrong, and that is the assertion.
            match ($next) {
                'billing_identity' => $this->configureBilling(),
                'plans' => $planId = $this->planId(),
                'offers' => $offerId = $this->offerId($this->createOffer((string) $planId)),
                'published' => self::assertSame(200, $this->publish((string) $offerId)->getStatusCode()),
                'advertised' => self::assertSame(200, $this->advertise((string) $offerId)->getStatusCode()),
                default => self::fail('unexpected step: ' . $next),
            };
        }

        $readiness = $this->decode($this->readiness());

        self::assertTrue($readiness['sellable'] ?? null, 'the chain did not reach a sellable product');
        // Null, not absent: it is what lets a screen say "done" rather than
        // point at nowhere.
        self::assertArrayHasKey('next', $readiness);
        self::assertNull($readiness['next']);

        // Five acts, in the order the chain named them, and nothing else.
        self::assertSame(
            ['billing_identity', 'plans', 'offers', 'published', 'advertised'],
            $done,
        );

        // And the claim is not this endpoint's opinion: a stranger with no
        // account can now see a price.
        $window = $this->decode($this->request('GET', '/api/v1/public/offers?product=atlas'))['offers'] ?? null;

        self::assertIsArray($window);
        self::assertCount(1, $window);
    }

    // --- Helpers -------------------------------------------------------------

    private function readiness(string $token = 'ola-token', int $expected = 200): ResponseInterface
    {
        $response = $this->request(
            'GET',
            '/api/v1/staff/readiness?product=atlas',
            ['Authorization' => 'Bearer ' . $token],
        );

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $steps = $this->decode($this->readiness())['steps'] ?? null;

        self::assertIsArray($steps);

        /** @var list<array<string, mixed>> $steps */
        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $key): array
    {
        foreach ($this->steps() as $step) {
            if (($step['key'] ?? null) === $key) {
                return $step;
            }
        }

        self::fail('no step named ' . $key);
    }

    private function configureBilling(): void
    {
        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/staff/configuration/billing-identity?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(self::ISSUER),
        )->getStatusCode());
    }

    private function planId(): string
    {
        $response = $this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->idIn($response, 'plan');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createOffer(string $planId, array $extra = []): ResponseInterface
    {
        $response = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => 'pro-monthly',
                'name' => 'Pro, monthly',
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ] + $extra),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    private function offerId(ResponseInterface $response): string
    {
        return $this->idIn($response, 'offer');
    }

    /**
     * The id of a named object in a response, narrowed once rather than at each
     * call site — chained offsets on a decoded body are `mixed` all the way
     * down, and PHPStan is right that they are.
     */
    private function idIn(ResponseInterface $response, string $key): string
    {
        $item = $this->decode($response)[$key] ?? null;

        self::assertIsArray($item);

        $id = $item['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(string $key): array
    {
        $detail = $this->step($key)['detail'] ?? null;

        self::assertIsArray($detail);

        /** @var array<string, mixed> $detail */
        return $detail;
    }

    private function publish(string $offerId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers/' . $offerId . '/publish?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['version' => 1]),
        );
    }

    private function advertise(string $offerId): ResponseInterface
    {
        return $this->request(
            'PUT',
            '/api/v1/staff/storefront/offers/' . $offerId . '?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['publicly_listed' => true]),
        );
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

    private function id(string $sql): string
    {
        $identifier = $this->connection->fetchOne($sql);

        self::assertIsString($identifier);

        return $identifier;
    }
}
