<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\PaymentProviders;
use App\Shared\Logging\ErrorLogLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The shop window, and the purchase that starts from it — through the real
 * pipeline, the real database and no session at all.
 *
 * Nothing is doubled here except the clock's worth of fixtures. The claims
 * under test are about what an *unauthenticated* request reaches, and a
 * doubled repository would answer with whatever the fixture said rather than
 * with what the SQL filters — which is the only thing standing between a
 * private price and the open internet.
 *
 * Three properties, in the order they matter:
 *
 *   - a stranger sees only what somebody deliberately advertised;
 *   - a stranger learns nothing else — not which products exist, not which
 *     offers exist, not whether an id is real;
 *   - a stranger can become a customer and buy, in two calls.
 */
#[CoversNothing]
final class StorefrontTest extends DatabaseApiTestCase
{
    /** Overridden rather than configured: AUTH_SIGNING_SECRET is read once per container. */
    private const SECRET = 'a-test-signing-secret-nobody-deploys';

    private const STAFF_PASSWORD = 'correct horse battery staple';

    private string $product = '';
    private string $plan = '';
    private string $advertised = '';
    private string $private = '';
    private string $staff = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 10) RETURNING id",
            ['p' => $this->product],
        );

        // Two offers, both genuinely on sale. The only difference is whether
        // anybody decided to advertise one — which is the whole distinction
        // this feature rests on.
        $this->advertised = $this->offer('pro-monthly', 'Pro, monthly', true);
        $this->private = $this->offer('reseller', 'Reseller terms', false);

        // A real administrator with a real password, rather than a fake
        // provider: this suite also exercises sign-up, which *issues* tokens,
        // and one AuthProvider cannot be both a double and the real verifier.
        $this->staff = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('pending', 'ola@platform.test') RETURNING id",
        );
        $this->connection->executeStatement(
            "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
            ['id' => $this->staff],
        );
        $this->connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            [
                'id' => $this->staff,
                'email' => 'ola@platform.test',
                'hash' => password_hash(self::STAFF_PASSWORD, PASSWORD_BCRYPT),
            ],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'
                SQL,
            ['user' => $this->staff],
        );

        // The platform's own legal identity, which every invoice carries and
        // which is deployment configuration rather than anything a sign-up
        // supplies. Without it the checkout below refuses for a reason that
        // has nothing to do with the storefront.
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

        $logger = new ErrorLogLogger();

        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer(
                self::SECRET,
                LocalTokens::DEFAULT_ISSUER,
                LocalTokens::DEFAULT_AUDIENCE,
            ),
            AuthProvider::class => new LocalJwtAuthProvider(
                self::SECRET,
                LocalTokens::DEFAULT_ISSUER,
                LocalTokens::DEFAULT_AUDIENCE,
                $logger,
            ),

            // The one double in this suite. Card data never reaches
            // PostgreSQL (§24), so there is nothing real to drive here and
            // the provider is not what the storefront is about.
            PaymentProviders::class => new PaymentProviders([new StubPaymentProvider(self::SECRET)]),
        ]);
    }

    // --- What a stranger sees -----------------------------------------------

    public function testTheWindowShowsOnlyWhatSomebodyAdvertised(): void
    {
        $response = $this->request('GET', '/api/v1/public/offers?product=atlas');

        self::assertSame(200, $response->getStatusCode());

        $codes = array_column($this->listIn($response, 'offers'), 'code');

        // Both are on sale. One is on the front page. That gap is the feature.
        self::assertSame(['pro-monthly'], $codes);
    }

    public function testItNeedsNoTokenAndNoProductHeader(): void
    {
        $response = $this->request('GET', '/api/v1/public/offers?product=atlas');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame(401, $response->getStatusCode());
    }

    public function testThePriceAndThePlanTravelWithTheOffer(): void
    {
        $offer = $this->listIn($this->request('GET', '/api/v1/public/offers?product=atlas'), 'offers')[0];
        $version = $offer['version'] ?? null;

        self::assertIsArray($version);

        $price = $version['price'] ?? null;

        self::assertIsArray($price);

        // A shop window without a price is a shop window nobody buys from.
        self::assertSame(2900, $price['minor_units'] ?? null);
        self::assertSame('EUR', $price['currency'] ?? null);
        self::assertIsArray($offer['plan'] ?? null);
    }

    public function testALinkToAnAdvertisedOfferOpens(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/public/offers/' . $this->advertised . '?product=atlas',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // --- What a stranger does not learn -------------------------------------

    public function testAPrivateOfferIsNotFoundEvenByItsRealId(): void
    {
        $response = $this->request('GET', '/api/v1/public/offers/' . $this->private . '?product=atlas');

        // The id is real and the offer is on sale. 404 anyway, and identical
        // to the answer for an id that names nothing — or the two become a way
        // to test ids against the private catalogue.
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            $this->errorOf($response)['code'],
            $this->errorOf($this->request(
                'GET',
                '/api/v1/public/offers/00000000-0000-0000-0000-000000000000?product=atlas',
            ))['code'],
        );
    }

    public function testAnUnknownProductIsAnEmptyWindowRatherThanANotFound(): void
    {
        $response = $this->request('GET', '/api/v1/public/offers?product=no-such-product');

        // 200 with nothing in it, and no product echoed back. A 404 here would
        // answer "does this deployment host a product called X?" for any X.
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);

        self::assertArrayHasKey('product', $body);
        self::assertNull($body['product']);
        self::assertSame([], $this->listIn($response, 'offers'));
    }

    public function testAProductThatAdvertisesNothingLooksTheSameAsOneThatDoesNotExist(): void
    {
        $this->connection->executeStatement('UPDATE offers SET publicly_listed = false');

        $empty = $this->decode($this->request('GET', '/api/v1/public/offers?product=atlas'));
        $missing = $this->decode($this->request('GET', '/api/v1/public/offers?product=nope'));

        self::assertSame($missing, $empty);
    }

    public function testAnInactiveProductAdvertisesNothing(): void
    {
        $this->connection->executeStatement('UPDATE products SET active = false');

        self::assertSame([], $this->listIn(
            $this->request('GET', '/api/v1/public/offers?product=atlas'),
            'offers',
        ));
    }

    public function testTheProductMustBeNamed(): void
    {
        $response = $this->request('GET', '/api/v1/public/offers');

        // Not defaulted: guessing would make the answer depend on a deployment
        // setting the caller cannot see.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code']);
    }

    public function testAnOfferOutsideItsSaleWindowIsNotAdvertisedEitherWayRound(): void
    {
        $this->connection->executeStatement(
            "UPDATE offer_versions SET valid_until = now() - interval '1 day'
              WHERE offer_id = :offer",
            ['offer' => $this->advertised],
        );

        // Advertised and unsellable is a price somebody cannot buy at, and a
        // storefront that shows one is a storefront that lies.
        self::assertSame([], $this->listIn(
            $this->request('GET', '/api/v1/public/offers?product=atlas'),
            'offers',
        ));
    }

    // --- Which windows there are (ADR-047, amending ADR-041) ----------------

    public function testTheProductListNamesOnlyProductsWithSomethingOnSale(): void
    {
        // A second product with an offer nobody advertised, and a third with
        // nothing at all: neither is a window, so neither is in the list.
        $boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 10) RETURNING id",
            ['p' => $boreas],
        );
        $this->connection->executeStatement(
            "INSERT INTO offers (product_id, plan_id, code, name, publicly_listed) VALUES (:p, :plan, 'quiet', 'Quiet', false)",
            ['p' => $boreas, 'plan' => $plan],
        );
        $this->connection->executeStatement("INSERT INTO products (code, name, active) VALUES ('comet', 'Comet', true)");

        $response = $this->request('GET', '/api/v1/public/products');

        // No token, no product header: a stranger's question.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['code' => 'atlas', 'name' => 'Atlas']], $this->listIn($response, 'products'));
    }

    public function testAProductStopsBeingListedWhenItsWindowEmpties(): void
    {
        self::assertCount(1, $this->listIn($this->request('GET', '/api/v1/public/products'), 'products'));

        // Out of its sale window: advertised, but nothing a person could buy.
        $this->connection->executeStatement(
            "UPDATE offer_versions SET valid_until = now() - interval '1 day' WHERE offer_id = :offer",
            ['offer' => $this->advertised],
        );

        self::assertSame([], $this->listIn($this->request('GET', '/api/v1/public/products'), 'products'));
    }

    public function testARetiredProductIsNotListedWhateverItAdvertised(): void
    {
        $this->connection->executeStatement('UPDATE products SET active = false');

        self::assertSame([], $this->listIn($this->request('GET', '/api/v1/public/products'), 'products'));
    }

    // --- Who decides what is advertised -------------------------------------

    public function testAdvertisingIsAPlatformDecisionAndIsRecorded(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/storefront/offers/' . $this->private . '?product=atlas',
            ['Authorization' => 'Bearer ' . $this->staffToken()],
            $this->json(['publicly_listed' => true]),
        );

        self::assertSame(200, $response->getStatusCode());

        $codes = array_column(
            $this->listIn($this->request('GET', '/api/v1/public/offers?product=atlas'), 'offers'),
            'code',
        );
        sort($codes);
        self::assertSame(['pro-monthly', 'reseller'], $codes);

        $trail = $this->connection->fetchAssociative(
            "SELECT action, permission FROM staff_access_log WHERE resource_type = 'offer'",
        );

        self::assertIsArray($trail);
        self::assertSame('ADVERTISE', $trail['action']);
        // "On what grounds?" — the question a trail without this cannot answer.
        self::assertSame('staff.catalog.manage', $trail['permission']);
    }

    public function testWithdrawingStopsAdvertisingWithoutStoppingSelling(): void
    {
        $this->advertiseOff($this->advertised);

        self::assertSame([], $this->listIn(
            $this->request('GET', '/api/v1/public/offers?product=atlas'),
            'offers',
        ));

        // Still on sale, still owned, still renewable. Un-selling an offer is a
        // commercial act with an invoice attached and is not this one.
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM offers'));
        self::assertSame(
            'ACTIVE',
            $this->connection->fetchOne(
                'SELECT status FROM offer_versions WHERE offer_id = :o',
                ['o' => $this->advertised],
            ),
        );
    }

    public function testStaffSeeWhatIsHiddenAsWellAsWhatIsNot(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/staff/storefront/offers?product=atlas',
            ['Authorization' => 'Bearer ' . $this->staffToken()],
        );

        self::assertSame(200, $response->getStatusCode());

        $offers = $this->listIn($response, 'offers');

        // Deciding what to advertise means seeing what you are choosing
        // between, so this is the unfiltered view — the opposite of the
        // endpoint it governs. Both offers, and each says which it is.
        self::assertCount(2, $offers);

        $listing = [];

        foreach ($offers as $offer) {
            self::assertIsString($offer['code'] ?? null);
            $listing[$offer['code']] = $offer['publicly_listed'] ?? null;
        }

        self::assertSame(['pro-monthly' => true, 'reseller' => false], $listing);
    }

    public function testSomebodyWithNoPlatformRoleCannotAdvertiseAnything(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/storefront/offers/' . $this->private . '?product=atlas',
            [],
            $this->json(['publicly_listed' => true]),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($this->isAdvertised($this->private));
    }

    // --- A stranger becomes a customer --------------------------------------

    public function testSigningUpCreatesAnAccountAnOrganisationAndASession(): void
    {
        $response = $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'display_name' => 'Ada',
            'organisation' => 'Acme Ltd',
            'product' => 'atlas',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decode($response);

        self::assertIsString($body['access_token'] ?? null);
        self::assertIsString($body['tenant_id'] ?? null);

        // The organisation carries the company name, and the person
        // administers it. Six rows or none.
        self::assertSame('Acme Ltd', $this->connection->fetchOne(
            'SELECT name FROM tenants WHERE id = :id',
            ['id' => $body['tenant_id']],
        ));
        // The product they arrived for is the organisation's first product
        // (ADR-047), and nobody on staff decided it.
        self::assertSame(1, $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM tenant_products tp
                  JOIN products p ON p.id = tp.product_id
                 WHERE tp.tenant_id = :tenant AND p.code = 'atlas' AND tp.assigned_by IS NULL
                SQL,
            ['tenant' => $body['tenant_id']],
        ));
        self::assertSame(1, $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM tenant_member_roles tmr
                  JOIN roles r ON r.id = tmr.role_id
                 WHERE tmr.tenant_id = :tenant AND r.code = 'TENANT_ADMIN'
                SQL,
            ['tenant' => $body['tenant_id']],
        ));
    }

    /**
     * The product signed up for is where this person's screens open from
     * then on (2026-09-17): said beside the product list — which a client
     * can read before it has named a product — and on `/me`, and changeable
     * from the profile to any product they hold, and to none of the others.
     */
    public function testTheProductSignedUpForIsTheDefaultUntilTheProfileSaysOtherwise(): void
    {
        $token = $this->decode($this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'display_name' => 'Ada',
            'product' => 'atlas',
        ]))['access_token'] ?? null;
        self::assertIsString($token);

        $bearer = ['Authorization' => 'Bearer ' . $token];
        $scoped = $bearer + ['X-Product' => 'atlas'];

        $products = $this->decode($this->request('GET', '/api/v1/products', $bearer));
        self::assertSame('atlas', $products['default'] ?? null);
        self::assertSame('atlas', $this->decode($this->request('GET', '/api/v1/me', $scoped))['default_product'] ?? null);

        // A product they do not hold is refused, and nothing moves.
        $this->connection->executeStatement("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true)");
        $refused = $this->request('PATCH', '/api/v1/me', $scoped, $this->json(['default_product' => 'boreas']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('PRODUCT_NOT_HELD', $this->errorOf($refused)['code'] ?? null);
        self::assertSame('atlas', $this->decode($this->request('GET', '/api/v1/products', $bearer))['default'] ?? null);

        // Cleared, the shell chooses; an absent field leaves it alone.
        $cleared = $this->request('PATCH', '/api/v1/me', $scoped, $this->json(['default_product' => null]));
        self::assertSame(200, $cleared->getStatusCode());
        $body = $this->decode($cleared);
        self::assertArrayHasKey('default_product', $body);
        self::assertNull($body['default_product']);
        $renamed = $this->decode($this->request('PATCH', '/api/v1/me', $scoped, $this->json(['display_name' => 'Ada L.'])));
        self::assertArrayHasKey('default_product', $renamed);
        self::assertNull($renamed['default_product']);

        // And set again to the one they hold.
        $set = $this->request('PATCH', '/api/v1/me', $scoped, $this->json(['default_product' => 'atlas']));
        self::assertSame('atlas', $this->decode($set)['default_product'] ?? null);
    }

    public function testAConsumerIsNotMadeToInventACompany(): void
    {
        $response = $this->signUp([
            'email' => 'sam@personal.test',
            'password' => 'a-long-enough-password',
            'display_name' => 'Sam Rivers',
            'product' => 'atlas',
        ]);

        self::assertSame(201, $response->getStatusCode());

        // An invoice still needs somebody to be addressed to, and that is who
        // it is. Nothing records that this was the B2C case, because nothing
        // downstream should behave differently.
        self::assertSame('Sam Rivers', $this->connection->fetchOne(
            'SELECT name FROM tenants WHERE id = :id',
            ['id' => $this->decode($response)['tenant_id']],
        ));
    }

    public function testTheNewAccountAdministersItsOwnOrganisationAndNothingElse(): void
    {
        $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'atlas',
        ]);

        // Not staff. The installer's first account is both because there is
        // nobody else to be the second one; somebody who bought a subscription
        // is not.
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM platform_staff'));

        // And not an offer author: ADR-040's flag stays false, so the platform
        // catalogue is not theirs to edit.
        self::assertFalse((bool) $this->connection->fetchOne(
            "SELECT may_author_offers FROM tenants WHERE name = 'ada@acme.test'",
        ));
    }

    public function testTheAddressIsUnverifiedAndTheAccountWorksAnyway(): void
    {
        $response = $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'atlas',
        ]);

        self::assertSame(201, $response->getStatusCode());

        // The doubt is recorded; nothing waits on it. An interrupted purchase
        // is a purchase that does not happen.
        self::assertNull($this->connection->fetchOne(
            "SELECT email_verified_at FROM users WHERE email = 'ada@acme.test'",
        ));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM email_verifications'));
    }

    public function testTheConfirmationLinkConfirmsOnceAndNeverSignsAnybodyIn(): void
    {
        $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'atlas',
        ]);

        $token = $this->emailedToken();

        $first = $this->request('POST', '/api/v1/auth/verify-email', [], $this->json(['token' => $token]));

        self::assertSame(200, $first->getStatusCode());
        // No token in the answer: a link that issued a session would be a
        // credential living in an inbox.
        self::assertArrayNotHasKey('access_token', $this->decode($first));
        self::assertNotNull($this->connection->fetchOne(
            "SELECT email_verified_at FROM users WHERE email = 'ada@acme.test'",
        ));

        // Opened again from a second tab: refused, and identically to a token
        // that never existed.
        $second = $this->request('POST', '/api/v1/auth/verify-email', [], $this->json(['token' => $token]));

        self::assertSame(400, $second->getStatusCode());
        self::assertSame('VERIFICATION_FAILED', $this->errorOf($second)['code']);
    }

    public function testAnAddressThatAlreadyHasAnAccountIsSaidPlainly(): void
    {
        $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'atlas',
        ]);

        $again = $this->signUp([
            'email' => 'ADA@acme.test',
            'password' => 'another-long-password',
            'product' => 'atlas',
        ]);

        // Case-insensitive, and answered rather than hidden: somebody who
        // cannot be told cannot finish the purchase they came for, and the
        // sign-in form is one click away.
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('EMAIL_TAKEN', $this->errorOf($again)['code']);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM tenants'));
    }

    public function testSigningUpForAProductThatDoesNotExistCreatesNothing(): void
    {
        $response = $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'no-such-product',
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenants'));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM users'));
    }

    public function testAShortPasswordIsRefusedBeforeAnythingIsWritten(): void
    {
        $response = $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'short',
            'product' => 'atlas',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenants'));
    }

    public function testTheSessionItIssuesCanImmediatelyBuyTheOfferTheyChose(): void
    {
        $token = $this->decode($this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'organisation' => 'Acme Ltd',
            'product' => 'atlas',
        ]))['access_token'];

        self::assertIsString($token);

        // The whole point: the purchase that follows is an ordinary
        // authenticated checkout, on the membership the sign-up created, with
        // no second anonymous flow and no rules of its own.
        $checkout = $this->request(
            'POST',
            '/api/v1/checkout/sessions',
            ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'],
            $this->json(['offer_id' => $this->advertised]),
        );

        self::assertSame(201, $checkout->getStatusCode());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM orders'));
    }

    public function testSigningInWithTheNewCredentialWorks(): void
    {
        $this->signUp([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
            'product' => 'atlas',
        ]);

        $response = $this->request('POST', '/api/v1/auth/token', [], $this->json([
            'email' => 'ada@acme.test',
            'password' => 'a-long-enough-password',
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * An administrator's access token, obtained the way a person gets one.
     */
    private function staffToken(): string
    {
        $response = $this->request('POST', '/api/v1/auth/token', [], $this->json([
            'email' => 'ola@platform.test',
            'password' => self::STAFF_PASSWORD,
        ]));

        self::assertSame(200, $response->getStatusCode());

        $token = $this->decode($response)['access_token'] ?? null;

        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function signUp(array $body): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/sign-up', [], $this->json($body));
    }

    /**
     * The token as it left for the person's inbox, pulled back out of the link.
     *
     * Read from the notification payload rather than from the database row,
     * which holds only a SHA-256 — which is the property being relied on. The
     * payload carries a whole link rather than a bare token because what is
     * emailed has to be clickable.
     */
    private function emailedToken(): string
    {
        $payload = $this->connection->fetchOne(
            "SELECT payload FROM notifications WHERE type = 'account.email_verification'",
        );

        self::assertIsString($payload);

        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded);

        $link = $decoded['link'] ?? null;

        self::assertIsString($link);
        self::assertStringContainsString('/sign-in?verify=', $link);

        return substr($link, (int) strpos($link, 'verify=') + 7);
    }

    private function advertiseOff(string $offerId): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/storefront/offers/' . $offerId . '?product=atlas',
            ['Authorization' => 'Bearer ' . $this->staffToken()],
            $this->json(['publicly_listed' => false]),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function isAdvertised(string $offerId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT publicly_listed FROM offers WHERE id = :id',
            ['id' => $offerId],
        );
    }

    /**
     * An offer with one ACTIVE version on sale from yesterday, for ever.
     */
    private function offer(string $code, string $name, bool $advertised): string
    {
        $offerId = $this->id(
            <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
                VALUES (:product, :plan, :code, :name, :listed)
                RETURNING id
                SQL,
            [
                'product' => $this->product,
                'plan' => $this->plan,
                'code' => $code,
                'name' => $name,
                'listed' => $advertised ? 'true' : 'false',
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'ACTIVE', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')
                SQL,
            ['offer' => $offerId],
        );

        return $offerId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listIn(ResponseInterface $response, string $key): array
    {
        $rows = $this->decode($response)[$key] ?? null;

        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
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
