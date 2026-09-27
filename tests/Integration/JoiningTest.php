<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Shared\Logging\ErrorLogLogger;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Who may join an organisation by themselves (docs/tenant-roots.md §2.4).
 *
 * A sign-up at `hostname/acme/` asks Acme to have the person, as a USER.
 * Acme's join policy answers: `APPROVAL` writes the membership PENDING and
 * tells the administrators, `DOMAIN` admits a listed domain at once and
 * refuses the rest, `INVITATION` refuses everybody. A pending membership
 * grants nothing — not a context, not a seat on the members list — until
 * an administrator accepts it, and a declined one leaves the account with
 * nowhere to go and nothing else changed.
 */
#[CoversNothing]
final class JoiningTest extends DatabaseApiTestCase
{
    private const SECRET = 'a-test-signing-secret-nobody-deploys';
    private const PASSWORD = 'correct horse battery staple';

    private string $atlas = '';
    private string $boreas = '';
    private string $acme = '';
    private string $ann = '';
    private string $uma = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);
        TestDatabase::assignProduct($this->connection, $this->acme, $this->boreas);

        // Ann administers Acme; Uma is an ordinary member. Both with real
        // passwords, because sign-up issues real tokens and one provider
        // cannot be both a double and the verifier.
        $this->ann = $this->person('ann@acme.test');
        $this->uma = $this->person('uma@acme.test');
        $this->member($this->ann, 'TENANT_ADMIN');
        $this->member($this->uma, 'USER');

        $logger = new ErrorLogLogger();
        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer(self::SECRET, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE),
            AuthProvider::class => new LocalJwtAuthProvider(self::SECRET, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE, $logger),
        ]);
    }

    // --- OPEN, the default ----------------------------------------------------

    /**
     * Since 2026-09-18 the default admits at once: the operator's reading of
     * self-service is that somebody who signs up at a root and chooses an
     * offer pays for it there and then, and a USER may (billing.pay).
     */
    public function testByDefaultAStrangerIsInAtOnceAndMayBuy(): void
    {
        $response = $this->signUp('zed@elsewhere.test');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('ACTIVE', $this->decode($response)['membership'] ?? null);

        $me = $this->decode($this->request('GET', '/api/v1/me/permissions', ['Authorization' => 'Bearer ' . $this->tokenIn($response), 'X-Product' => 'atlas']));
        $permissions = $me['permissions'] ?? null;
        self::assertIsArray($permissions);
        self::assertContains('billing.pay', $permissions);
        self::assertContains('subscription.manage', $permissions);
        self::assertNotContains('billing.manage', $permissions);
        self::assertNotContains('payments.manage', $permissions);

        // Nobody was asked, nobody was told.
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'member.requested'"));
    }

    // --- APPROVAL ----------------------------------------------------------------

    public function testUnderApprovalAStrangerWaitsAndTheAdministratorsAreTold(): void
    {
        $this->policy('APPROVAL');
        $response = $this->signUp('zed@elsewhere.test');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('PENDING', $this->decode($response)['membership'] ?? null);

        $token = $this->tokenIn($response);

        // Nothing resolves for them yet: no context, so no `/me`.
        $me = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas']);
        self::assertSame(403, $me->getStatusCode());

        // But the product list, which needs no product, says where they wait.
        $products = $this->decode($this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame([], $products['products'] ?? null);
        self::assertSame([['tenant' => 'acme', 'name' => 'Acme Ltd', 'waiting_on' => 'ADMINISTRATOR']], $products['pending_memberships'] ?? null);
        // Waiting is not belonging: no root is theirs yet.
        self::assertSame([], $products['memberships'] ?? null);

        // The administrator was told, once; the ordinary member was not.
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT count(*) FROM notifications WHERE type = 'member.requested' AND recipient_user_id = :ann",
            ['ann' => $this->ann],
        ));
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT count(*) FROM notifications WHERE type = 'member.requested' AND recipient_user_id = :uma",
            ['uma' => $this->uma],
        ));

        // On the members screen they are a request, not a member.
        $requests = $this->decode($this->request('GET', '/api/v1/tenants/current/members/requests', $this->as('ann@acme.test')));
        self::assertSame(['zed@elsewhere.test'], array_column($this->listIn($requests, 'requests'), 'email'));
        self::assertSame('PENDING', $this->listIn($requests, 'requests')[0]['status'] ?? null);
        $members = $this->decode($this->request('GET', '/api/v1/tenants/current/members', $this->as('ann@acme.test')));
        self::assertNotContains('zed@elsewhere.test', array_column($this->listIn($members, 'members'), 'email'));
    }

    public function testAcceptingMakesTheMembershipLiveOnEveryProduct(): void
    {
        $this->policy('APPROVAL');
        $stranger = $this->tokenIn($this->signUp('zed@elsewhere.test'));
        $zed = $this->userId('zed@elsewhere.test');

        $accepted = $this->request('POST', '/api/v1/tenants/current/members/' . $zed . '/accept', $this->as('ann@acme.test'));

        self::assertSame(204, $accepted->getStatusCode());

        // And a root of their own now: the shell puts the address under it.
        $products = $this->decode($this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $stranger]));
        // `default_product` rides along since 2026-09-26: the shell needs to
        // know which product the organisation opens on before it has one to
        // ask anything else with, and this is the read that can answer.
        self::assertSame(
            [['tenant' => 'acme', 'name' => 'Acme Ltd', 'default_product' => null]],
            $products['memberships'] ?? null,
        );
        self::assertSame([], $products['pending_memberships'] ?? null);

        // Both products, in one decision (ADR-047), and still a USER.
        foreach (['atlas', 'boreas'] as $code) {
            $me = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $stranger, 'X-Product' => $code]);
            self::assertSame(200, $me->getStatusCode(), $code);
            self::assertSame($this->acme, $this->decode($me)['tenant_id'] ?? null);
        }
        self::assertSame(['USER'], $this->connection->fetchFirstColumn(
            'SELECT DISTINCT r.code FROM tenant_member_roles tmr JOIN roles r ON r.id = tmr.role_id WHERE tmr.user_id = :user',
            ['user' => $zed],
        ));

        // Nothing left waiting, and a second acceptance has nobody to accept.
        $requests = $this->decode($this->request('GET', '/api/v1/tenants/current/members/requests', $this->as('ann@acme.test')));
        self::assertSame([], $requests['requests'] ?? null);
        $again = $this->request('POST', '/api/v1/tenants/current/members/' . $zed . '/accept', $this->as('ann@acme.test'));
        self::assertSame(404, $again->getStatusCode());
        self::assertSame('JOIN_REQUEST_NOT_FOUND', $this->errorOf($again)['code'] ?? null);
    }

    public function testDecliningDropsTheRequestAndKeepsTheAccount(): void
    {
        $this->policy('APPROVAL');
        $stranger = $this->tokenIn($this->signUp('zed@elsewhere.test'));
        $zed = $this->userId('zed@elsewhere.test');

        $declined = $this->request('POST', '/api/v1/tenants/current/members/' . $zed . '/decline', $this->as('ann@acme.test'));

        self::assertSame(204, $declined->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_members WHERE user_id = :user', ['user' => $zed]));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM users WHERE id = :user', ['user' => $zed]));

        // Still signed in, nowhere to go, and no longer waiting anywhere.
        $products = $this->decode($this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $stranger]));
        self::assertSame([], $products['products'] ?? null);
        self::assertSame([], $products['pending_memberships'] ?? null);
    }

    public function testOnlyAnAdministratorDecides(): void
    {
        $this->policy('APPROVAL');
        $this->signUp('zed@elsewhere.test');
        $zed = $this->userId('zed@elsewhere.test');

        // Reading the queue is members.read, which a USER holds; deciding is
        // members.manage, which they do not.
        self::assertSame(200, $this->request('GET', '/api/v1/tenants/current/members/requests', $this->as('uma@acme.test'))->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v1/tenants/current/members/' . $zed . '/accept', $this->as('uma@acme.test'))->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v1/tenants/current/members/' . $zed . '/decline', $this->as('uma@acme.test'))->getStatusCode());
        self::assertSame('PENDING', $this->connection->fetchOne('SELECT status FROM tenant_members WHERE user_id = :user LIMIT 1', ['user' => $zed]));
    }

    // --- DOMAIN and INVITATION --------------------------------------------------

    /**
     * A domain is proved, not typed (ADR-061). Signing up with an address on
     * the list writes a membership that waits for the address — and until
     * the link is followed it reaches nothing, whatever the address says.
     */
    public function testUnderDomainAListedAddressWaitsForItsProofAndAnyOtherIsRefused(): void
    {
        $this->domainPolicy('acme.test');

        $colleague = $this->signUp('new@acme.test');
        self::assertSame(201, $colleague->getStatusCode());
        self::assertSame('UNCONFIRMED', $this->decode($colleague)['membership'] ?? null);

        $token = $this->tokenIn($colleague);

        // Typing the address is not being a member.
        self::assertSame(403, $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'])->getStatusCode());

        // The shell is told what it waits on: the mailbox, not an administrator.
        $products = $this->decode($this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame([['tenant' => 'acme', 'name' => 'Acme Ltd', 'waiting_on' => 'CONFIRMATION']], $products['pending_memberships'] ?? null);

        // Nobody was asked anything, and it is not on the administrator's list.
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'member.requested'"));
        $requests = $this->decode($this->request('GET', '/api/v1/tenants/current/members/requests', $this->as('ann@acme.test')));
        self::assertSame([], $requests['requests'] ?? null);

        // The link proves it, and the membership is live.
        self::assertSame(200, $this->followConfirmation('new@acme.test')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'])->getStatusCode());

        $stranger = $this->signUp('zed@elsewhere.test');
        self::assertSame(403, $stranger->getStatusCode());
        self::assertSame('JOIN_DOMAIN_NOT_ALLOWED', $this->errorOf($stranger)['code'] ?? null);
        // Refused before anything is written: no account to sign in with.
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM users WHERE email = 'zed@elsewhere.test'"));
    }

    /** The decision is taken again when the proof arrives, against the list as it stands then. */
    public function testADomainDroppedBeforeTheProofArrivesAdmitsNobody(): void
    {
        $this->domainPolicy('acme.test');
        $colleague = $this->signUp('new@acme.test');

        $this->connection->executeStatement('DELETE FROM tenant_join_domains WHERE tenant_id = :t', ['t' => $this->acme]);
        $this->connection->executeStatement("INSERT INTO tenant_join_domains (tenant_id, domain) VALUES (:t, 'acme.example')", ['t' => $this->acme]);

        self::assertSame(200, $this->followConfirmation('new@acme.test')->getStatusCode());

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_members WHERE user_id = :u', ['u' => $this->userId('new@acme.test')]));
        self::assertSame(403, $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $this->tokenIn($colleague), 'X-Product' => 'atlas'])->getStatusCode());
    }

    /** A password link reaches the same mailbox, so it proves the address too. */
    public function testAPasswordResetProvesTheAddressAndLetsADomainMemberIn(): void
    {
        $this->domainPolicy('acme.test');
        $this->signUp('new@acme.test');

        $this->request('POST', '/api/v1/auth/password/forgot', [], $this->json(['email' => 'new@acme.test']));
        $link = $this->latestLink('account.password_reset', 'new@acme.test');
        $reset = $this->request('POST', '/api/v1/auth/password/reset', [], $this->json([
            'token' => substr($link, (int) strpos($link, 'reset=') + 6),
            'password' => 'another-long-enough-password',
        ]));
        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());

        self::assertSame('ACTIVE', $this->connection->fetchOne(
            'SELECT status FROM tenant_members WHERE user_id = :u LIMIT 1',
            ['u' => $this->userId('new@acme.test')],
        ));
    }

    // --- The deadline to prove an address (ADR-061) ------------------------------

    /**
     * Registering never waits: under OPEN the person is in, and may buy, at
     * once — with a deadline to prove the address, reported where the shell
     * can read it before anything is refused.
     */
    public function testASelfServiceSignUpIsInAtOnceAndToldItsDeadline(): void
    {
        $response = $this->signUp('zed@elsewhere.test');
        $token = $this->tokenIn($response);

        self::assertSame(200, $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'])->getStatusCode());

        $address = $this->decode($this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token]))['address'] ?? null;
        self::assertIsArray($address);
        self::assertFalse($address['confirmed'] ?? true);
        self::assertIsString($address['confirm_by'] ?? null);
        self::assertGreaterThan(time() + 6 * 86400, strtotime((string) $address['confirm_by']));
    }

    /**
     * Past the deadline, unproved: the tenant surface waits for the click,
     * with a refusal of its own — and the way out stays open.
     */
    public function testPastTheDeadlineTheTenantSurfaceWaitsForTheClickAndTheWayOutStaysOpen(): void
    {
        $response = $this->signUp('zed@elsewhere.test');
        $token = $this->tokenIn($response);
        $this->overdue('zed@elsewhere.test');

        $me = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas']);
        self::assertSame(403, $me->getStatusCode());
        self::assertSame('EMAIL_UNCONFIRMED', $this->errorOf($me)['code'] ?? null);

        // Identity-only reads still answer, so the shell can say why.
        self::assertSame(200, $this->request('GET', '/api/v1/products', ['Authorization' => 'Bearer ' . $token])->getStatusCode());

        // A new link, once; a second press within the minute sends nothing.
        $resend = $this->request('POST', '/api/v1/auth/verify-email/resend', ['Authorization' => 'Bearer ' . $token]);
        self::assertSame(200, $resend->getStatusCode(), (string) $resend->getBody());
        self::assertTrue($this->decode($resend)['sent'] ?? false);
        $again = $this->request('POST', '/api/v1/auth/verify-email/resend', ['Authorization' => 'Bearer ' . $token]);
        self::assertFalse($this->decode($again)['sent'] ?? true);
        self::assertSame(2, $this->connection->fetchOne(
            "SELECT count(*) FROM notifications WHERE type = 'account.email_verification' AND recipient_user_id = :u",
            ['u' => $this->userId('zed@elsewhere.test')],
        ));

        // The new link proves it, and everything is back — nothing was cancelled.
        self::assertSame(200, $this->followConfirmation('zed@elsewhere.test')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'])->getStatusCode());

        // And a proved address asks for no more links.
        self::assertFalse($this->decode($this->request('POST', '/api/v1/auth/verify-email/resend', ['Authorization' => 'Bearer ' . $token]))['sent'] ?? true);
    }

    /**
     * A deadline nobody was given is not enforced: accounts named by an
     * administrator or an operator have none, whatever their address.
     */
    public function testAnAccountThatDidNotSignUpByItselfHasNoDeadline(): void
    {
        $this->connection->executeStatement('UPDATE users SET email_verified_at = NULL WHERE id = :u', ['u' => $this->uma]);

        self::assertSame(200, $this->request('GET', '/api/v1/me', $this->as('uma@acme.test'))->getStatusCode());
    }

    public function testUnderInvitationNobodyArrivesByThemselves(): void
    {
        $this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json(['join_policy' => 'INVITATION']));

        $response = $this->signUp('new@acme.test');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('JOIN_BY_INVITATION', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM users WHERE email = 'new@acme.test'"));
    }

    // --- The policy itself -------------------------------------------------------

    public function testThePolicyIsReadWithTheOrganisationAndDomainNeedsADomain(): void
    {
        $shown = $this->tenantIn($this->request('GET', '/api/v1/tenants/current', $this->as('ann@acme.test')));
        self::assertSame('OPEN', $shown['join_policy'] ?? null);
        self::assertSame([], $shown['join_domains'] ?? null);

        $empty = $this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json(['join_policy' => 'DOMAIN']));
        self::assertSame(400, $empty->getStatusCode());
        self::assertSame('JOIN_DOMAINS_REQUIRED', $this->errorOf($empty)['code'] ?? null);

        $bogus = $this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json(['join_policy' => 'ANYONE']));
        self::assertSame(400, $bogus->getStatusCode());

        $notADomain = $this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json(['join_domains' => ['@acme.test']]));
        self::assertSame(400, $notADomain->getStatusCode());

        // Unchanged by all of it, and renaming touches nothing but the name.
        $renamed = $this->tenantIn($this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json(['name' => 'Acme Limited'])));
        self::assertSame('Acme Limited', $renamed['name'] ?? null);
        self::assertSame('OPEN', $renamed['join_policy'] ?? null);

        // A USER reads it and may not set it.
        self::assertSame(403, $this->request('PATCH', '/api/v1/tenants/current', $this->as('uma@acme.test'), $this->json(['join_policy' => 'INVITATION']))->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------

    private function signUp(string $email): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/sign-up', [], $this->json([
            'email' => $email,
            'password' => 'a-long-enough-password',
            'display_name' => 'Somebody',
            'tenant' => 'acme',
            'product' => 'atlas',
        ]));
    }

    private function domainPolicy(string $domain): void
    {
        $set = $this->request('PATCH', '/api/v1/tenants/current', $this->as('ann@acme.test'), $this->json([
            'join_policy' => 'DOMAIN',
            'join_domains' => [$domain],
        ]));
        self::assertSame(200, $set->getStatusCode());
    }

    /** Moves somebody's deadline into the past, as a week would. */
    private function overdue(string $email): void
    {
        $this->connection->executeStatement(
            "UPDATE users SET email_confirm_by = now() - interval '1 minute' WHERE email = :email",
            ['email' => $email],
        );
        // As if the last link had gone out long ago, so a resend is allowed.
        $this->connection->executeStatement(
            "UPDATE email_verifications SET created_at = now() - interval '1 day' WHERE user_id = (SELECT id FROM users WHERE email = :email)",
            ['email' => $email],
        );
    }

    /** The link in the most recent mail of this type to this address. */
    private function latestLink(string $type, string $email): string
    {
        $payload = $this->connection->fetchOne(
            'SELECT payload FROM notifications n JOIN users u ON u.id = n.recipient_user_id
              WHERE n.type = :type AND u.email = :email ORDER BY n.created_at DESC, n.id DESC LIMIT 1',
            ['type' => $type, 'email' => $email],
        );
        self::assertIsString($payload);
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        $link = $decoded['link'] ?? null;
        self::assertIsString($link);

        return $link;
    }

    private function followConfirmation(string $email): ResponseInterface
    {
        $link = $this->latestLink('account.email_verification', $email);

        return $this->request('POST', '/api/v1/auth/verify-email', [], $this->json([
            'token' => substr($link, (int) strpos($link, 'verify=') + 7),
        ]));
    }

    private function policy(string $policy): void
    {
        $this->connection->executeStatement('UPDATE tenants SET join_policy = :policy WHERE id = :id', ['policy' => $policy, 'id' => $this->acme]);
    }

    /** @return array<string, string> */
    private function as(string $email): array
    {
        $response = $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => $email, 'password' => self::PASSWORD]));
        self::assertSame(200, $response->getStatusCode());

        return ['Authorization' => 'Bearer ' . $this->tokenIn($response), 'X-Product' => 'atlas'];
    }

    /** @return array<string, mixed> */
    private function tenantIn(ResponseInterface $response): array
    {
        $tenant = $this->decode($response)['tenant'] ?? null;
        self::assertIsArray($tenant);
        $typed = [];
        foreach ($tenant as $k => $v) {
            $typed[(string) $k] = $v;
        }

        return $typed;
    }

    private function tokenIn(ResponseInterface $response): string
    {
        $token = $this->decode($response)['access_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function userId(string $email): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => $email]);
        self::assertIsString($id);

        return $id;
    }

    private function person(string $email): string
    {
        $id = $this->id("INSERT INTO users (auth_subject, email) VALUES ('pending', :email) RETURNING id", ['email' => $email]);
        $this->connection->executeStatement("UPDATE users SET auth_subject = 'local:' || id WHERE id = :id", ['id' => $id]);
        $this->connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            ['id' => $id, 'email' => $email, 'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT)],
        );

        return $id;
    }

    private function member(string $userId, string $role): void
    {
        foreach ([$this->atlas, $this->boreas] as $product) {
            $this->connection->executeStatement(
                'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
                ['tenant' => $this->acme, 'user' => $userId, 'product' => $product],
            );
            $this->connection->executeStatement(
                'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = :role',
                ['tenant' => $this->acme, 'user' => $userId, 'product' => $product, 'role' => $role],
            );
        }
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function listIn(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        self::assertIsArray($list);
        $rows = [];
        foreach ($list as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $k => $v) {
                $typed[(string) $k] = $v;
            }
            $rows[] = $typed;
        }

        return $rows;
    }
}
