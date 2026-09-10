<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Skin\Controller\SkinRoute;
use App\Storage\Domain\StorageProvider;
use App\Storage\Infrastructure\LocalStorageProvider;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * White label, end to end (§7, §12.1).
 *
 * Two gates have to hold independently here, which is the whole reason this
 * feature is interesting: `skin.manage` says a person may configure the
 * tenant, `white_label` says the tenant's plan includes the feature, and
 * neither implies the other. §37.4 order puts both refusals first.
 *
 * The storage provider is the real local one writing to a real directory,
 * because "a refused logo leaves nothing behind" is a claim about the store
 * and an in-memory double would make it true by construction.
 */
#[CoversNothing]
final class SkinEndpointsTest extends DatabaseApiTestCase
{
    /** A real 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $product = '';
    private string $tenant = '';
    private string $plainTenant = '';
    private string $admin = '';
    private string $member = '';
    private string $plainAdmin = '';
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/backprod-skin-' . bin2hex(random_bytes(6));

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->plainTenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Basic', 'basic') RETURNING id",
        );

        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id",
        );
        $this->member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-raj', 'raj@acme.test') RETURNING id",
        );
        $this->plainAdmin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-bo', 'bo@basic.test') RETURNING id",
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'raj-token' => 'sub-raj',
                'bo-token' => 'sub-bo',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            StorageProvider::class => new LocalStorageProvider($this->root),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                // Ada can configure and her plan includes white label.
                new TenantMembership($this->tenant, $this->admin, $this->product, ['TENANT_ADMIN'], ['skin.manage']),
                // Raj is on the same plan and may not configure anything.
                new TenantMembership($this->tenant, $this->member, $this->product, ['USER'], []),
                // Bo can configure, on a plan that did not buy white label.
                new TenantMembership(
                    $this->plainTenant,
                    $this->plainAdmin,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['skin.manage'],
                ),
            ]),

            EntitlementRepository::class => InMemoryEntitlementRepository::granting([
                $this->tenant . ':' . $this->product => [SkinRoute::CAPABILITY],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($this->root);
        }

        parent::tearDown();
    }

    // --- The two gates ------------------------------------------------------

    public function testAMemberWhoMayNotConfigureTheTenantIsRefused(): void
    {
        $response = $this->patch(['primary_color' => '#1f4b99'], 'raj-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    public function testATenantAdminOnAPlanWithoutWhiteLabelIsRefused(): void
    {
        // Bo holds exactly the permission Ada holds. What he lacks is the
        // plan feature, and the two refusals are different for a reason: one
        // is answered by an administrator, the other by an upgrade.
        $response = $this->patch(['primary_color' => '#1f4b99'], 'bo-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('ENTITLEMENT_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    public function testReadingTheSkinNeedsNeitherOfThem(): void
    {
        // Raj may not configure; Bo's plan has no white label. Both still
        // have to be able to render the product they are looking at.
        self::assertSame(200, $this->get('raj-token')->getStatusCode());
        self::assertSame(200, $this->get('bo-token')->getStatusCode());
    }

    // --- What is refused ----------------------------------------------------

    public function testAColourThatIsAStylesheetIsRefused(): void
    {
        $response = $this->patch(['primary_color' => 'red;} body{display:none']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testANonImageIsRefusedAndLeavesNothingBehind(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/tenant/skin/logo',
            $this->headers() + ['X-Filename' => 'logo.png'],
            'this is not a picture, whatever the filename claims',
        );

        self::assertSame(422, $response->getStatusCode());

        // The type is sniffed before anything is written, so the refusal
        // leaves no asset row and no file in the store.
        $assets = $this->connection->fetchOne('SELECT count(*) FROM assets');
        self::assertSame('0', (string) (is_scalar($assets) ? $assets : 'not counted'));
        self::assertSame([], glob($this->root . '/*') ?: []);
    }

    // --- What it does -------------------------------------------------------

    public function testAnUnsetSkinAnswersWithNullsRatherThanANotFound(): void
    {
        $skin = $this->skinOf($this->get('ada-token'));

        self::assertNull($skin['primary_color']);
        self::assertNull($skin['accent_color']);
        self::assertNull($skin['logo_asset_id']);
    }

    public function testColoursAreStoredAndNormalisedToLowerCase(): void
    {
        $skin = $this->skinOf($this->patch(['primary_color' => '#1F4B99', 'accent_color' => '#AA0000']));

        self::assertSame('#1f4b99', $skin['primary_color']);
        self::assertSame('#aa0000', $skin['accent_color']);
    }

    public function testOmittingAFieldKeepsItAndSendingNullClearsIt(): void
    {
        $this->patch(['primary_color' => '#1f4b99', 'accent_color' => '#aa0000']);

        // Not mentioned: left alone.
        $kept = $this->skinOf($this->patch(['primary_color' => '#222222']));
        self::assertSame('#aa0000', $kept['accent_color']);

        // Mentioned as null: cleared.
        $cleared = $this->skinOf($this->patch(['accent_color' => null]));
        self::assertNull($cleared['accent_color']);
        self::assertSame('#222222', $cleared['primary_color']);
    }

    public function testALogoIsStoredAsAnAssetAndPointedAt(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/tenant/skin/logo',
            $this->headers() + ['X-Filename' => 'acme.png'],
            (string) base64_decode(self::PNG, true),
        );

        self::assertSame(201, $response->getStatusCode());

        $logo = $this->skinOf($response)['logo_asset_id'];
        self::assertIsString($logo);

        // An asset, so it goes through the same sniffing and the same signed
        // links as every other file rather than through a second path.
        $type = $this->connection->fetchOne(
            'SELECT content_type FROM assets WHERE id = :id',
            ['id' => $logo],
        );
        self::assertSame('image/png', $type);
    }

    public function testRemovingTheLogoKeepsTheFile(): void
    {
        $upload = $this->request(
            'POST',
            '/api/v1/tenant/skin/logo',
            $this->headers() + ['X-Filename' => 'acme.png'],
            (string) base64_decode(self::PNG, true),
        );
        $logo = $this->skinOf($upload)['logo_asset_id'];
        self::assertIsString($logo);

        $after = $this->skinOf($this->request('DELETE', '/api/v1/tenant/skin/logo', $this->headers()));

        self::assertNull($after['logo_asset_id']);

        // "Stop using this as our logo" is not "destroy this file": it may be
        // on a page somebody has open or in a document already generated.
        $still = $this->connection->fetchOne('SELECT count(*) FROM assets WHERE id = :id', ['id' => $logo]);
        self::assertSame('1', (string) (is_scalar($still) ? $still : 'not counted'));
    }

    public function testTheSkinIsPerProductAndPerTenant(): void
    {
        $this->patch(['primary_color' => '#1f4b99']);

        // Bo is a different tenant on the same product and sees his own
        // absence of a skin, not Acme's.
        $other = $this->skinOf($this->get('bo-token'));

        self::assertNull($other['primary_color']);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param array<string, string|null> $body
     */
    private function patch(array $body, string $token = 'ada-token'): ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/tenant/skin', $this->headers($token), $this->json($body));
    }

    private function get(string $token = 'ada-token'): ResponseInterface
    {
        return $this->request('GET', '/api/v1/tenant/skin', $this->headers($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function skinOf(ResponseInterface $response): array
    {
        $skin = $this->decode($response)['skin'] ?? null;

        self::assertIsArray($skin);

        /** @var array<string, mixed> $skin */
        return $skin;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token = 'ada-token'): array
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
