<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Commerce\Service\Subscriptions;
use App\Privacy\Domain\ErasureRepository;
use App\Shared\Context\RoutePolicy;
use App\Shared\Http\Middleware\CorsMiddleware;
use App\Shared\Throttle\RateLimitMiddleware;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use App\Throttle\Infrastructure\PostgresRateLimiter;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Share links (ADR-073), through the real pipeline over the real database.
 *
 * The claims, each a decision rather than a mechanism:
 *
 *   1. the token leaves **once**, inside a URL whose fragment carries it, and
 *      only its hash is stored;
 *   2. the link shows the project **as last saved**, and nothing about the
 *      organisation, the holder or the sender beyond the name they chose;
 *   3. **one 404** for every way a link can fail — malformed, unknown,
 *      expired, revoked, a deleted project, a holder no longer covered;
 *   4. making one asks coverage, the write permission, the feature and the
 *      reach, and each refusal is its own; listing and revoking ask no feature;
 *   5. the public read takes no tenant or product from the request and is held
 *      to the public rate limit.
 *
 * Against PostgreSQL because the expiry is the database's clock, the scope is a
 * composite foreign key and the coverage that silences a link is the real query
 * — a double would be a second implementation of all three.
 */
#[CoversNothing]
final class ProjectSharesTest extends DatabaseApiTestCase
{
    private const APP_URL = 'https://plan.example.test';
    private const PUBLIC_LIMIT = 5;

    private string $product = '';
    private string $acme = '';
    private string $globex = '';

    /** Holds a Pro seat on Acme: the sender. */
    private string $ada = '';
    /** Holds a Pro seat on Acme too: the colleague whose work is their own. */
    private string $dave = '';
    /** Holds a Lecture seat on Acme, which sells no sharing. */
    private string $cy = '';
    /** Holds a Pro seat on Acme and a role that may only read. */
    private string $eva = '';
    /** Holds a Pro seat on Globex. */
    private string $gus = '';

    private string $pro = '';
    private string $lecture = '';
    private string $globexPro = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active, app_url) VALUES ('plan', 'Plan', true, :url) RETURNING id",
            ['url' => self::APP_URL],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, 'project_schema_versions', CAST('{"supported":[1]}' AS jsonb))
                SQL,
            ['product' => $this->product],
        );

        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        TestDatabase::assignProduct($this->connection, $this->acme, $this->product);
        TestDatabase::assignProduct($this->connection, $this->globex, $this->product);

        $this->ada = $this->person('sub-ada', 'ada@acme.test');
        $this->dave = $this->person('sub-dave', 'dave@acme.test');
        $this->cy = $this->person('sub-cy', 'cy@acme.test');
        $this->eva = $this->person('sub-eva', 'eva@acme.test');
        $this->gus = $this->person('sub-gus', 'gus@globex.test');

        $this->seedCatalogue();

        $writer = ['projects.read', 'projects.write'];

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'dave-token' => 'sub-dave',
                'cy-token' => 'sub-cy',
                'eva-token' => 'sub-eva',
                'gus-token' => 'sub-gus',
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->acme, $this->ada, $this->product, ['USER'], $writer),
                new TenantMembership($this->acme, $this->dave, $this->product, ['USER'], $writer),
                new TenantMembership($this->acme, $this->cy, $this->product, ['USER'], $writer),
                new TenantMembership($this->acme, $this->eva, $this->product, ['USER'], ['projects.read']),
                new TenantMembership($this->globex, $this->gus, $this->product, ['USER'], $writer),
            ]),
        ]);

        $this->subscribe($this->acme, $this->pro, $this->ada);
        $this->subscribe($this->acme, $this->pro, $this->dave);
        $this->subscribe($this->acme, $this->lecture, $this->cy);
        $this->subscribe($this->acme, $this->pro, $this->eva);
        $this->subscribe($this->globex, $this->globexPro, $this->gus);
    }

    // --- 1. the token leaves once, and is stored hashed ---------------------------

    public function testALinkIsMadeOnceAndOpensTheProjectAsSaved(): void
    {
        $project = $this->project('ada-token', 'Terrasse', ['deck' => ['boards' => 42]]);

        $created = $this->share('ada-token', $project, ['display_name' => 'Ada', 'recipient_label' => 'Le menuisier']);
        self::assertSame(201, $created->getStatusCode());

        $body = $this->decode($created);
        self::assertSame('ACTIVE', $body['status'] ?? null);
        self::assertSame('Ada', $body['display_name'] ?? null);
        self::assertSame('Le menuisier', $body['recipient_label'] ?? null);
        self::assertSame(0, $body['open_count'] ?? null);

        $url = $body['url'] ?? null;
        self::assertIsString($url);
        self::assertStringStartsWith(self::APP_URL . '/?product=plan#share=', $url);

        $token = self::tokenOf($url);
        self::assertSame(43, strlen($token), '256 bits, base64url');

        // Expires 30 days after it was made, by default.
        self::assertSame(30, self::lifetimeOf($body));

        $opened = $this->open($token);
        self::assertSame(200, $opened->getStatusCode());
        self::assertSame('no-store', $opened->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $opened->getHeaderLine('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $opened->getHeaderLine('X-Robots-Tag'));

        $shown = $this->decode($opened);
        self::assertSame('Ada', $shown['shared_by'] ?? null);
        self::assertSame($body['expires_at'], $shown['expires_at'] ?? null);

        $shownProject = $shown['project'] ?? null;
        self::assertIsArray($shownProject);
        self::assertSame('Terrasse', $shownProject['name'] ?? null);
        self::assertSame(1, $shownProject['schema_version'] ?? null);
        self::assertSame(['deck' => ['boards' => 42]], $shownProject['document'] ?? null);

        // Nothing about the organisation, the holder or the sender's note.
        $raw = (string) $opened->getBody();
        foreach ([$this->acme, $this->ada, $this->product, $project, 'Le menuisier', 'Acme'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    public function testOnlyTheHashIsStoredAndTheListNeverCarriesTheToken(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        $row = $this->connection->fetchAssociative('SELECT * FROM project_shares');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash']);

        foreach ($row as $value) {
            self::assertStringNotContainsString($token, is_scalar($value) ? (string) $value : '');
        }

        $listed = $this->list('ada-token', $project);
        self::assertSame(200, $listed->getStatusCode());
        self::assertStringNotContainsString($token, (string) $listed->getBody());
        self::assertStringNotContainsString('url', (string) $listed->getBody());
    }

    // --- 2. the link follows the project ------------------------------------------

    public function testTheLinkShowsTheLatestSavedVersionAndCountsOpenings(): void
    {
        $project = $this->project('ada-token', 'Terrasse', ['deck' => ['boards' => 42]]);
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        self::assertSame(200, $this->open($token)->getStatusCode());

        $patched = $this->request('PATCH', '/api/v1/projects/' . $project, $this->headers('ada-token'), $this->json([
            'document' => ['deck' => ['boards' => 48]],
        ]));
        self::assertSame(200, $patched->getStatusCode());

        $shown = $this->decode($this->open($token))['project'] ?? null;
        self::assertIsArray($shown);
        self::assertSame(['deck' => ['boards' => 48]], $shown['document'] ?? null);
        self::assertSame($this->decode($patched)['updated_at'] ?? null, $shown['updated_at'] ?? null);

        $listed = $this->onlyShare('ada-token', $project);
        self::assertSame(2, $listed['open_count'] ?? null);
        self::assertIsString($listed['last_opened_at'] ?? null);
    }

    // --- 3. one 404 for every way a link can fail ---------------------------------

    public function testEveryFailedLinkAnswersTheSame(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        $revoked = self::tokenOf($this->urlOf($this->share('ada-token', $project)));
        $expired = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        $revokedId = $this->shareIdOf($revoked);

        $first = $this->revoke('ada-token', $project, $revokedId);
        // Revoking again asks for what is already true.
        $second = $this->revoke('ada-token', $project, $revokedId);
        self::assertSame(204, $first->getStatusCode());
        self::assertSame(204, $second->getStatusCode());

        $this->age($expired, days: 31, lifetime: 30);

        $answers = [
            'revoked' => $this->open($revoked),
            'expired' => $this->open($expired),
            'unknown' => $this->open(str_repeat('A', 43)),
            'malformed' => $this->open('not-a-token'),
            'empty' => $this->open(''),
        ];

        foreach ($answers as $case => $answer) {
            self::assertSame(404, $answer->getStatusCode(), $case);
            self::assertSame('SHARE_NOT_FOUND', $this->errorOf($answer)['code'] ?? null, $case);
            self::assertSame([], $this->errorOf($answer)['details'] ?? null, $case);
        }

        $statuses = [];
        foreach ($this->sharesOf('ada-token', $project) as $share) {
            self::assertIsString($share['id'] ?? null);
            $statuses[$share['id']] = $share['status'] ?? null;
        }

        self::assertSame('REVOKED', $statuses[$revokedId]);
        self::assertSame('EXPIRED', $statuses[$this->shareIdOf($expired)]);
    }

    public function testADeletedProjectsLinkAnswersNotFoundUntilItIsPutBack(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        self::assertSame(204, $this->request('DELETE', '/api/v1/projects/' . $project, $this->headers('ada-token'))->getStatusCode());
        self::assertSame(404, $this->open($token)->getStatusCode());

        self::assertSame(200, $this->request('POST', '/api/v1/projects/' . $project . '/undelete', $this->headers('ada-token'))->getStatusCode());
        self::assertSame(200, $this->open($token)->getStatusCode());
    }

    /**
     * A link is a window onto the workshop, and the workshop is suspended when
     * whoever pays for the work is in arrears (ADR-060). Paying reopens both.
     */
    public function testALinkFallsSilentWhileItsHolderIsNotCovered(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        $subscription = $this->id('SELECT id FROM subscriptions WHERE subscriber_user_id = :ada', ['ada' => $this->ada]);
        $invoice = $this->id(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, subscription_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units, issued_at)
                VALUES (:tenant, :product, :subscription, '2026-000001', 'ISSUED', 'EUR', 2900, 580, 3480, now())
                RETURNING id
                SQL,
            ['tenant' => $this->acme, 'product' => $this->product, 'subscription' => $subscription],
        );
        $this->connection->executeStatement(
            "UPDATE subscriptions SET status = 'PAST_DUE', past_due_since = now(), past_due_invoice_id = :invoice WHERE id = :id",
            ['id' => $subscription, 'invoice' => $invoice],
        );
        self::assertSame(404, $this->open($token)->getStatusCode());
        self::assertSame('SHARE_NOT_FOUND', $this->errorOf($this->open($token))['code'] ?? null);

        $this->connection->executeStatement(
            "UPDATE subscriptions SET status = 'ACTIVE', past_due_since = NULL, past_due_invoice_id = NULL WHERE id = :id",
            ['id' => $subscription],
        );
        self::assertSame(200, $this->open($token)->getStatusCode());
    }

    // --- 4. who may make, list and revoke one -------------------------------------

    public function testAColleagueWhoDoesNotReachTheProjectFindsNothing(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));
        $shareId = $this->shareIdOf($token);

        // Dave sells sharing and may write — on his own work. Ada's is not.
        foreach ([
            $this->share('dave-token', $project),
            $this->list('dave-token', $project),
            $this->revoke('dave-token', $project, $shareId),
        ] as $answer) {
            self::assertSame(404, $answer->getStatusCode());
            self::assertSame('PROJECT_NOT_FOUND', $this->errorOf($answer)['code'] ?? null);
        }

        self::assertSame(200, $this->open($token)->getStatusCode(), 'and the link still answers');
    }

    public function testAnotherOrganisationFindsNothing(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $shareId = $this->shareIdOf(self::tokenOf($this->urlOf($this->share('ada-token', $project))));

        self::assertSame(404, $this->share('gus-token', $project)->getStatusCode());
        self::assertSame(404, $this->list('gus-token', $project)->getStatusCode());
        self::assertSame(404, $this->revoke('gus-token', $project, $shareId)->getStatusCode());
    }

    /** A link of another project is not found through this one, even by its own holder. */
    public function testALinkIsOnlyFoundThroughItsOwnProject(): void
    {
        $terrace = $this->project('ada-token', 'Terrasse');
        $pergola = $this->project('ada-token', 'Pergola');
        $shareId = $this->shareIdOf(self::tokenOf($this->urlOf($this->share('ada-token', $terrace))));

        $answer = $this->revoke('ada-token', $pergola, $shareId);
        self::assertSame(404, $answer->getStatusCode());
        self::assertSame('SHARE_NOT_FOUND', $this->errorOf($answer)['code'] ?? null);
        self::assertSame(404, $this->revoke('ada-token', $terrace, 'not-a-uuid')->getStatusCode());
    }

    /** Plan's Lecture sells no `project_sharing`, and that is the whole of why it makes no link. */
    public function testASeatThatSellsNoSharingMakesNoLink(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        $answer = $this->share('cy-token', $project);
        self::assertSame(403, $answer->getStatusCode());
        self::assertSame('ENTITLEMENT_REQUIRED', $this->errorOf($answer)['code'] ?? null);
    }

    public function testARoleThatMayOnlyReadMakesNoLink(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        $answer = $this->share('eva-token', $project);
        self::assertSame(403, $answer->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($answer)['code'] ?? null);
    }

    /**
     * Listing and revoking ask no feature: somebody whose offer stopped selling
     * sharing must still see what they handed out, and take it back.
     */
    public function testLinksStayListableAndRevocableWithoutTheFeature(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        $this->connection->executeStatement(
            "DELETE FROM entitlements WHERE feature_id = (SELECT id FROM features WHERE code = 'project_sharing')",
        );

        self::assertSame(403, $this->share('ada-token', $project)->getStatusCode(), 'no new link');
        self::assertSame(200, $this->open($token)->getStatusCode(), 'the one handed out still answers');
        self::assertSame(200, $this->list('ada-token', $project)->getStatusCode());
        self::assertSame(204, $this->revoke('ada-token', $project, $this->shareIdOf($token))->getStatusCode());
        self::assertSame(404, $this->open($token)->getStatusCode());
    }

    public function testTheLifetimeIsChosenWithinAYearAndNeverClamped(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        foreach ([0, 366, -1] as $days) {
            $answer = $this->share('ada-token', $project, ['expires_in_days' => $days]);
            self::assertSame(400, $answer->getStatusCode(), (string) $days);
            self::assertSame('VALIDATION_FAILED', $this->errorOf($answer)['code'] ?? null);
        }

        $year = $this->decode($this->share('ada-token', $project, ['expires_in_days' => 365]));
        self::assertSame(365, self::lifetimeOf($year));

        $day = $this->decode($this->share('ada-token', $project, ['expires_in_days' => 1, 'display_name' => '  ']));
        self::assertArrayHasKey('display_name', $day);
        self::assertNull($day['display_name'], 'blank reads as none');
    }

    public function testAProductWithNoPageOfItsOwnMakesNoLink(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $this->connection->executeStatement('UPDATE products SET app_url = NULL WHERE id = :id', ['id' => $this->product]);

        $answer = $this->share('ada-token', $project);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('SHARE_HAS_NO_VIEWER', $this->errorOf($answer)['code'] ?? null);
    }

    public function testAProjectHoldsAtMostFiftyLiveLinks(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_shares (tenant_id, product_id, project_id, token_hash, expires_at)
                SELECT :tenant, :product, :project, encode(sha256(g::text::bytea), 'hex'), now() + interval '1 day'
                  FROM generate_series(1, 50) AS g
                SQL,
            ['tenant' => $this->acme, 'product' => $this->product, 'project' => $project],
        );

        $answer = $this->share('ada-token', $project);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('SHARE_LIMIT_REACHED', $this->errorOf($answer)['code'] ?? null);
        self::assertSame(['limit' => 50], $this->errorOf($answer)['details'] ?? null);
    }

    /** The table holds a link to its project's own scope, whatever the code that writes it does. */
    public function testALinkCannotNameAProjectOfAnotherScope(): void
    {
        $project = $this->project('ada-token', 'Terrasse');

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_shares (tenant_id, product_id, project_id, token_hash, expires_at)
                VALUES (:tenant, :product, :project, :hash, now() + interval '1 day')
                SQL,
            ['tenant' => $this->globex, 'product' => $this->product, 'project' => $project, 'hash' => str_repeat('a', 64)],
        );
    }

    // --- 5. the public read ---------------------------------------------------------

    /** It reads no tenant and no product from the request: there is nothing there to believe. */
    public function testThePublicReadIgnoresAnyContextItIsSent(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project)));

        $answer = $this->request('POST', '/api/v1/public/shares/open', [
            'Authorization' => 'Bearer gus-token',
            'X-Product' => 'nonexistent',
            'X-Tenant' => $this->globex,
        ], $this->json(['token' => $token]));

        self::assertSame(200, $answer->getStatusCode());
    }

    public function testThePublicReadIsHeldToThePublicRateLimit(): void
    {
        $policy = $this->container()->get(RoutePolicy::class);
        self::assertInstanceOf(RoutePolicy::class, $policy);

        $this->override([
            RateLimitMiddleware::class => new RateLimitMiddleware(
                new PostgresRateLimiter($this->connection),
                $policy,
                60,
                1000,
                self::PUBLIC_LIMIT,
                [],
            ),
            CorsMiddleware::class => new CorsMiddleware([self::APP_URL]),
        ]);

        for ($i = 0; $i < self::PUBLIC_LIMIT; ++$i) {
            self::assertSame(404, $this->open(str_repeat('B', 43))->getStatusCode());
        }

        self::assertSame(429, $this->open(str_repeat('B', 43))->getStatusCode());
    }

    public function testErasureForgetsTheSendersWordsAndKeepsTheLink(): void
    {
        $project = $this->project('ada-token', 'Terrasse');
        $token = self::tokenOf($this->urlOf($this->share('ada-token', $project, [
            'display_name' => 'Ada Lovelace',
            'recipient_label' => 'Charles',
        ])));

        $erasure = $this->container()->get(ErasureRepository::class);
        self::assertInstanceOf(ErasureRepository::class, $erasure);
        $outcome = $erasure->erase($this->ada, $this->dave, null);

        self::assertSame(1, $outcome->erased['share_names'] ?? null);

        $row = $this->connection->fetchAssociative('SELECT display_name, recipient_label, revoked_at FROM project_shares');
        self::assertSame(['display_name' => null, 'recipient_label' => null, 'revoked_at' => null], $row);
        self::assertSame(hash('sha256', $token), $this->connection->fetchOne('SELECT token_hash FROM project_shares'));
    }

    // --- Helpers ----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $document
     */
    private function project(string $token, string $name, array $document = ['deck' => []]): string
    {
        $answer = $this->request('POST', '/api/v1/projects', $this->headers($token), $this->json([
            'name' => $name,
            'description' => 'A private note',
            'schema_version' => 1,
            'document' => $document,
        ]));

        self::assertSame(201, $answer->getStatusCode(), (string) $answer->getBody());
        $id = $this->decode($answer)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function share(string $token, string $project, array $body = []): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/projects/' . $project . '/shares',
            $this->headers($token),
            $body === [] ? null : $this->json($body),
        );
    }

    private function list(string $token, string $project): ResponseInterface
    {
        return $this->request('GET', '/api/v1/projects/' . $project . '/shares', $this->headers($token));
    }

    private function revoke(string $token, string $project, string $shareId): ResponseInterface
    {
        return $this->request('DELETE', '/api/v1/projects/' . $project . '/shares/' . $shareId, $this->headers($token));
    }

    private function open(string $token): ResponseInterface
    {
        return $this->request('POST', '/api/v1/public/shares/open', [], $this->json(['token' => $token]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharesOf(string $token, string $project): array
    {
        $shares = $this->decode($this->list($token, $project))['shares'] ?? null;
        self::assertIsArray($shares);

        /** @var list<array<string, mixed>> $shares */
        return $shares;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyShare(string $token, string $project): array
    {
        $shares = $this->sharesOf($token, $project);
        self::assertCount(1, $shares);

        return $shares[0];
    }

    private function urlOf(ResponseInterface $created): string
    {
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $url = $this->decode($created)['url'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function lifetimeOf(array $share): int
    {
        self::assertIsString($share['created_at'] ?? null);
        self::assertIsString($share['expires_at'] ?? null);

        return (new \DateTimeImmutable($share['created_at']))->diff(new \DateTimeImmutable($share['expires_at']))->days;
    }

    private static function tokenOf(string $url): string
    {
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        self::assertStringStartsWith('share=', $fragment);

        return substr($fragment, strlen('share='));
    }

    private function shareIdOf(string $token): string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM project_shares WHERE token_hash = :hash',
            ['hash' => hash('sha256', $token)],
        );
        self::assertIsString($id);

        return $id;
    }

    /** Moves a link into the past, keeping the constraint on its lifetime true. */
    private function age(string $token, int $days, int $lifetime): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_shares
                   SET created_at = now() - make_interval(days => :days),
                       expires_at = now() - make_interval(days => :days) + make_interval(days => :lifetime)
                 WHERE token_hash = :hash
                SQL,
            ['days' => $days, 'lifetime' => $lifetime, 'hash' => hash('sha256', $token)],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'plan'];
    }

    private function subscribe(string $tenant, string $offer, string $person): void
    {
        $subscriptions = $this->container()->get(Subscriptions::class);
        self::assertInstanceOf(Subscriptions::class, $subscriptions);

        $subscriptions->subscribe($tenant, $this->product, $offer, $person, $person);
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

    private function person(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
    }

    /**
     * Pro sells projects, people and sharing; Lecture sells only a place.
     * Globex's Pro is its own offer only because an offer belongs to a product
     * and the product is shared: the grants are the same.
     */
    private function seedCatalogue(): void
    {
        $feature = 'INSERT INTO features (code, name, kind, unit) VALUES (:code, :code, :kind, :unit) RETURNING id';
        $projects = $this->id($feature, ['code' => 'max_projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $users = $this->id($feature, ['code' => 'users', 'kind' => 'QUOTA', 'unit' => 'users']);
        $sharing = $this->id($feature, ['code' => 'project_sharing', 'kind' => 'BOOLEAN', 'unit' => null]);

        $this->pro = $this->offer('PRO', 'pro', [[$projects, 50], [$users, 1], [$sharing, null]]);
        $this->lecture = $this->offer('LECTURE', 'lecture', [[$users, 1]]);
        $this->globexPro = $this->offer('PRO2', 'pro-globex', [[$projects, 50], [$users, 1], [$sharing, null]]);
    }

    /**
     * @param list<array{0: string, 1: int|null}> $grants
     */
    private function offer(string $planCode, string $offerCode, array $grants): string
    {
        $plan = $this->id(
            'INSERT INTO plans (product_id, code, name, rank) VALUES (:product, :code, :code, 20) RETURNING id',
            ['product' => $this->product, 'code' => $planCode],
        );

        $offer = $this->id(
            'INSERT INTO offers (product_id, plan_id, code, name) VALUES (:product, :plan, :code, :code) RETURNING id',
            ['product' => $this->product, 'plan' => $plan, 'code' => $offerCode],
        );

        $version = $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'DRAFT', 'MONTHLY', 2900, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $offer],
        );

        foreach ($grants as [$featureId, $limit]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                    VALUES (:version, :feature, :limit)
                    SQL,
                ['version' => $version, 'feature' => $featureId, 'limit' => $limit],
            );
        }

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        return $offer;
    }
}
