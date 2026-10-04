<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\DefaultTenant;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Theme\Domain\ThemeDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Palettes (2026-10-04): fifteen to start with, edited by the platform
 * administrator alone; and which one each organisation wears in each product
 * it holds — one row, written from the console's matrix or from the
 * organisation's own screen, and read the same from both.
 */
#[CoversNothing]
final class PalettesTest extends DatabaseApiTestCase
{
    /** The fifteen the migrations seed, in the order offered. */
    private const SEEDED = [
        'petrol-classic', 'forest-ledger', 'terracotta-studio', 'midnight-indigo', 'graphite-compact',
        'monochrome', 'psychedelic', 'high-contrast', 'pastel-dream', 'ocean-depth',
        'sunset-glow', 'neon-night', 'nordic-frost', 'vintage-sepia', 'royal-velvet',
    ];

    /** The pairs text is set in, foreground on ground. */
    private const PAIRS = [
        ['ink', 'canvas'], ['ink', 'surface'], ['ink', 'well'], ['ink', 'raised'], ['ink', 'accent-wash'],
        ['muted', 'canvas'], ['muted', 'surface'], ['muted', 'well'],
        ['subtle', 'canvas'], ['subtle', 'surface'],
        ['accent', 'surface'], ['accent', 'canvas'], ['on-accent', 'accent'], ['accent-strong', 'accent-wash'],
        ['on-inverse', 'inverse'],
        ['success', 'success-wash'], ['warning', 'warning-wash'], ['danger', 'danger-wash'], ['info', 'info-wash'],
        ['muted', 'success-wash'], ['muted', 'warning-wash'], ['muted', 'danger-wash'], ['muted', 'info-wash'],
    ];

    private const OLA = 'ola-token';     // PLATFORM_ADMIN
    private const SAM = 'sam-token';     // SUPPORT_ADMIN
    private const ADA = 'ada-token';     // Acme's administrator
    private const RAJ = 'raj-token';     // Acme's member
    private const BO = 'bo-token';       // Basic's administrator

    private string $atlas = '';
    private string $beta = '';
    private string $acme = '';
    private string $basic = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->beta = $this->id("INSERT INTO products (code, name, active) VALUES ('beta', 'Beta', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->basic = $this->id("INSERT INTO tenants (name, slug) VALUES ('Basic', 'basic') RETURNING id");

        // Acme holds both products, Basic only Atlas: Basic has no Beta cell.
        foreach ([[$this->acme, $this->atlas], [$this->acme, $this->beta], [$this->basic, $this->atlas]] as [$tenant, $product]) {
            $this->connection->executeStatement('INSERT INTO tenant_products (tenant_id, product_id) VALUES (:t, :p)', ['t' => $tenant, 'p' => $product]);
        }

        $users = [];

        foreach (['ola', 'sam', 'ada', 'raj', 'bo'] as $name) {
            $users[$name] = $this->id('INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id', ['s' => "sub-{$name}", 'e' => "{$name}@example.test"]);
        }

        foreach (['ola' => 'PLATFORM_ADMIN', 'sam' => 'SUPPORT_ADMIN'] as $name => $role) {
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $users[$name], 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(array_combine(
                array_map(static fn (string $name): string => "{$name}-token", array_keys($users)),
                array_map(static fn (string $name): string => "sub-{$name}", array_keys($users)),
            )),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->atlas, 'atlas', 'Atlas', true),
                new Product($this->beta, 'beta', 'Beta', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->acme, $users['ada'], $this->atlas, ['TENANT_ADMIN'], ['skin.manage']),
                new TenantMembership($this->acme, $users['raj'], $this->atlas, ['USER'], []),
                new TenantMembership($this->basic, $users['bo'], $this->atlas, ['TENANT_ADMIN'], ['skin.manage']),
            ]),
            EntitlementRepository::class => InMemoryEntitlementRepository::granting([]),
        ]);
    }

    /**
     * The seeded ones are reference data, which no reset touches — so what a test
     * adds is taken back out, or the next test starts with sixteen.
     */
    protected function tearDown(): void
    {
        $this->connection->executeStatement("DELETE FROM tenant_palettes WHERE palette LIKE 'test-%'");
        $this->connection->executeStatement("DELETE FROM palettes WHERE name LIKE 'test-%'");

        parent::tearDown();
    }

    // --- the palettes ---------------------------------------------------------

    public function testFifteenPalettesAreOfferedInOrderEachComplete(): void
    {
        $palettes = $this->palettes(self::OLA);
        self::assertSame(self::SEEDED, array_keys($palettes));

        foreach ($palettes as $document) {
            self::assertSame($document, ThemeDocument::fromArray($document)->toArray());
            self::assertIsString($document['label'] ?? null);
            self::assertIsString($document['description'] ?? null);
        }

        // An organisation is offered the same ones.
        self::assertSame(self::SEEDED, array_column($this->list($this->call('GET', '/api/v1/tenant/palettes', null, self::ADA), 'palettes'), 'name'));
    }

    /** What an organisation's screens wear has to be readable. */
    public function testEveryPaletteClearsAaOnEveryPairInBothModes(): void
    {
        $failing = [];

        foreach ($this->palettes(self::OLA) as $name => $document) {
            $values = [];

            foreach ($this->tokens($document) as $token) {
                $values[$token['name']] = $token;
            }

            foreach (['light', 'dark'] as $mode) {
                foreach (self::PAIRS as [$fg, $bg]) {
                    $ratio = self::contrast($values[$fg][$mode] ?? '', $values[$bg][$mode] ?? '');

                    if ($ratio < 4.5) {
                        $failing[] = sprintf('%s %s: %s on %s is %.2f', $name, $mode, $fg, $bg, $ratio);
                    }
                }
            }
        }

        self::assertSame([], $failing);
    }

    public function testThePlatformAdministratorCreatesAndEditsAPalette(): void
    {
        $forest = $this->palettes(self::OLA)['forest-ledger'];

        $created = $this->call('PUT', '/api/v1/staff/palettes/test-autumn', ['document' => [...$forest, 'label' => 'Autumn']], self::OLA);
        self::assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame([...self::SEEDED, 'test-autumn'], array_keys($this->palettes(self::OLA)));

        $edited = self::recolour([...$forest, 'label' => 'Autumn'], 'accent', 'light', '#14532d');
        $this->call('PUT', '/api/v1/staff/palettes/test-autumn', ['document' => $edited], self::OLA);
        self::assertSame($edited, $this->palettes(self::OLA)['test-autumn']);
        // Edited in place, and still last.
        self::assertSame('test-autumn', array_key_last($this->palettes(self::OLA)));

        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'SAVE_PALETTE'"));
    }

    public function testNobodyElseChangesAPalette(): void
    {
        $document = $this->palettes(self::OLA)['petrol-classic'];

        self::assertSame(403, $this->call('PUT', '/api/v1/staff/palettes/test-x', ['document' => $document], self::SAM)->getStatusCode());
        self::assertSame(403, $this->call('GET', '/api/v1/staff/palettes', null, self::SAM)->getStatusCode());
        // An organisation has no route to a palette at all, only to its choice.
        self::assertContains($this->call('PUT', '/api/v1/tenant/palettes/test-x', ['document' => $document], self::ADA)->getStatusCode(), [404, 405]);

        self::assertSame(self::SEEDED, array_keys($this->palettes(self::OLA)));
    }

    public function testADocumentOutOfShapeIsRefusedWhole(): void
    {
        $broken = self::recolour($this->palettes(self::OLA)['terracotta-studio'], 'canvas', 'dark', '#000; } body { display: none');

        $response = $this->call('PUT', '/api/v1/staff/palettes/test-broken', ['document' => $broken], self::OLA);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
        self::assertSame(self::SEEDED, array_keys($this->palettes(self::OLA)));
    }

    // --- the matrix and the choice -------------------------------------------

    public function testTheMatrixHasACellOnlyWhereTheTenantHoldsTheProduct(): void
    {
        $this->call('PUT', $this->cell($this->acme, $this->atlas), ['palette' => 'forest-ledger'], self::OLA);

        $matrix = $this->call('GET', '/api/v1/staff/palette-assignments', null, self::OLA);
        self::assertSame(200, $matrix->getStatusCode());
        self::assertSame(['atlas', 'beta'], array_column($this->list($matrix, 'products'), 'code'));
        self::assertSame(2, $this->at($matrix, 'total'));

        self::assertSame([$this->atlas => 'forest-ledger', $this->beta => null], $this->cells($matrix, 'Acme'));
        self::assertSame([$this->atlas => null], $this->cells($matrix, 'Basic'));
    }

    public function testTheConsoleAndTheOrganisationWriteOneRow(): void
    {
        // The organisation chooses; the console sees it.
        $chosen = $this->call('PUT', '/api/v1/tenant/palette', ['palette' => 'terracotta-studio'], self::ADA);
        self::assertSame(200, $chosen->getStatusCode(), (string) $chosen->getBody());
        self::assertSame('terracotta-studio', $this->cells($this->call('GET', '/api/v1/staff/palette-assignments', null, self::OLA), 'Acme')[$this->atlas]);

        // The console reassigns; the organisation and its members see that.
        $this->call('PUT', $this->cell($this->acme, $this->atlas), ['palette' => 'midnight-indigo'], self::OLA);
        self::assertSame('midnight-indigo', $this->at($this->call('GET', '/api/v1/tenant/palettes', null, self::ADA), 'selected'));
        self::assertSame('midnight-indigo', $this->at($this->call('GET', '/api/v1/tenant/palette', null, self::RAJ), 'palette', 'name'));

        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM tenant_palettes'));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'ASSIGN_PALETTE'"));

        // Null is the platform's own design, from either door.
        self::assertNull($this->at($this->call('PUT', '/api/v1/tenant/palette', ['palette' => null], self::ADA), 'palette'));
        self::assertNull($this->at($this->call('GET', '/api/v1/tenant/palette', null, self::RAJ), 'palette'));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_palettes'));
    }

    public function testAnAssignmentNamesAPaletteThatExistsInAProductTheTenantHolds(): void
    {
        $unheld = $this->call('PUT', $this->cell($this->basic, $this->beta), ['palette' => 'forest-ledger'], self::OLA);
        self::assertSame(404, $unheld->getStatusCode());
        self::assertSame('TENANT_OR_PRODUCT_NOT_FOUND', $this->errorOf($unheld)['code'] ?? null);

        $unknown = $this->call('PUT', $this->cell($this->acme, $this->atlas), ['palette' => 'nowhere'], self::OLA);
        self::assertSame('PALETTE_NOT_FOUND', $this->errorOf($unknown)['code'] ?? null);
        self::assertSame(404, $this->call('PUT', '/api/v1/tenant/palette', ['palette' => 'nowhere'], self::ADA)->getStatusCode());

        self::assertSame(404, $this->call('PUT', $this->cell('not-a-uuid', $this->atlas), ['palette' => null], self::OLA)->getStatusCode());
        self::assertSame(400, $this->call('PUT', '/api/v1/tenant/palette', ['colour' => 'x'], self::ADA)->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_palettes'));
    }

    public function testAnOrganisationChoosesForItselfAndNobodyElse(): void
    {
        $this->call('PUT', '/api/v1/tenant/palette', ['palette' => 'graphite-compact'], self::BO);

        self::assertNull($this->at($this->call('GET', '/api/v1/tenant/palette', null, self::RAJ), 'palette'));
        self::assertSame('graphite-compact', $this->cells($this->call('GET', '/api/v1/staff/palette-assignments', null, self::OLA), 'Basic')[$this->atlas]);
    }

    public function testAMemberReadsWhatTheirScreensWearAndChoosesNothing(): void
    {
        self::assertSame(200, $this->call('GET', '/api/v1/tenant/palette', null, self::RAJ)->getStatusCode());

        foreach ([['GET', '/api/v1/tenant/palettes', null], ['PUT', '/api/v1/tenant/palette', ['palette' => 'forest-ledger']]] as [$method, $path, $body]) {
            $response = $this->call($method, $path, $body, self::RAJ);
            self::assertSame(403, $response->getStatusCode(), "{$method} {$path}");
            self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
        }

        foreach ([['GET', '/api/v1/staff/palette-assignments', null], ['PUT', $this->cell($this->acme, $this->atlas), ['palette' => null]]] as [$method, $path, $body]) {
            self::assertSame(403, $this->call($method, $path, $body, self::SAM)->getStatusCode(), "{$method} {$path}");
        }

        self::assertSame(['PLATFORM_ADMIN'], $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT r.code
                  FROM platform_role_permissions rp
                  JOIN platform_roles r ON r.id = rp.platform_role_id
                  JOIN platform_permissions p ON p.id = rp.platform_permission_id
                 WHERE p.code = 'staff.design.manage'
                SQL,
        ));
    }

    // --- the public page ------------------------------------------------------

    /**
     * A stranger sees the palette the organisation chose for that product, at
     * its root — and the default organisation's at the bare host. No session,
     * no header: a public read, of what the public page already shows.
     */
    public function testAStrangerSeesTheOrganisationsPaletteOnItsPublicPage(): void
    {
        $this->call('PUT', '/api/v1/tenant/palette', ['palette' => 'vintage-sepia'], self::ADA);
        $this->call('PUT', $this->cell($this->acme, $this->beta), ['palette' => 'neon-night'], self::OLA);

        $atlas = $this->request('GET', '/api/v1/public/palette?product=atlas&tenant=acme');
        self::assertSame(200, $atlas->getStatusCode(), (string) $atlas->getBody());
        self::assertSame('vintage-sepia', $this->at($atlas, 'palette', 'name'));
        self::assertIsArray($this->at($atlas, 'palette', 'document', 'colors'));

        // One palette per product: the same organisation wears another in Beta.
        self::assertSame('neon-night', $this->at($this->request('GET', '/api/v1/public/palette?product=beta&tenant=acme'), 'palette', 'name'));

        // The bare host is the default organisation's page.
        $default = $this->container()->get(DefaultTenant::class);
        self::assertInstanceOf(DefaultTenant::class, $default);
        $default->set($this->acme);
        self::assertSame('vintage-sepia', $this->at($this->request('GET', '/api/v1/public/palette?product=atlas'), 'palette', 'name'));
    }

    /** Every way of naming nothing answers the same nothing. */
    public function testThePublicReadNamesNothingItShouldNot(): void
    {
        $this->call('PUT', $this->cell($this->acme, $this->atlas), ['palette' => 'forest-ledger'], self::OLA);
        $this->connection->executeStatement("UPDATE products SET active = false WHERE code = 'beta'");

        foreach ([
            '/api/v1/public/palette?product=atlas&tenant=nobody',   // no such organisation
            '/api/v1/public/palette?product=nothing&tenant=acme',   // no such product
            '/api/v1/public/palette?product=atlas&tenant=basic',    // nothing chosen
            '/api/v1/public/palette?product=beta&tenant=basic',     // not held
            '/api/v1/public/palette?product=atlas',                 // no default organisation
        ] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame(['palette' => null], $this->decode($response), $path);
        }

        self::assertSame(400, $this->request('GET', '/api/v1/public/palette?tenant=acme')->getStatusCode());
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body, string $token): ResponseInterface
    {
        $headers = ['Authorization' => 'Bearer ' . $token];

        if (str_starts_with($path, '/api/v1/tenant/')) {
            $headers['X-Product'] = 'atlas';
        }

        return $this->request($method, $path, $headers, $body === null ? null : $this->json($body));
    }

    private function cell(string $tenant, string $product): string
    {
        return "/api/v1/staff/tenants/{$tenant}/products/{$product}/palette";
    }

    private function at(ResponseInterface $response, string|int ...$path): mixed
    {
        $value = $this->decode($response);

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(ResponseInterface $response, string $key): array
    {
        $list = $this->at($response, $key);
        self::assertIsArray($list);
        $rows = [];

        foreach ($list as $row) {
            self::assertIsArray($row);
            $out = [];

            foreach ($row as $k => $v) {
                $out[(string) $k] = $v;
            }

            $rows[] = $out;
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>> name => document
     */
    private function palettes(string $token): array
    {
        $palettes = [];

        foreach ($this->list($this->call('GET', '/api/v1/staff/palettes', null, $token), 'palettes') as $palette) {
            $name = $palette['name'] ?? null;
            $document = $palette['document'] ?? null;
            self::assertIsString($name);
            self::assertIsArray($document);
            $typed = [];

            foreach ($document as $k => $v) {
                $typed[(string) $k] = $v;
            }

            $palettes[$name] = $typed;
        }

        return $palettes;
    }

    /**
     * @return array<string, string|null> product id => palette
     */
    private function cells(ResponseInterface $matrix, string $tenant): array
    {
        foreach ($this->list($matrix, 'tenants') as $row) {
            if (($row['name'] ?? null) !== $tenant) {
                continue;
            }

            $cells = [];

            foreach (is_array($row['products'] ?? null) ? $row['products'] : [] as $cell) {
                if (is_array($cell) && is_string($cell['product_id'] ?? null)) {
                    $palette = $cell['palette'] ?? null;
                    $cells[$cell['product_id']] = is_string($palette) ? $palette : null;
                }
            }

            ksort($cells);
            $expected = [$this->atlas, $this->beta];
            uksort($cells, static fn (string $a, string $b): int => array_search($a, $expected, true) <=> array_search($b, $expected, true));

            return $cells;
        }

        self::fail("No row for {$tenant}.");
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array{name: string, light: string, dark: string}>
     */
    private function tokens(array $document): array
    {
        $tokens = [];

        foreach (is_array($document['colors'] ?? null) ? $document['colors'] : [] as $group) {
            foreach (is_array($group) && is_array($group['tokens'] ?? null) ? $group['tokens'] : [] as $token) {
                if (is_array($token) && is_string($token['name'] ?? null) && is_string($token['light'] ?? null) && is_string($token['dark'] ?? null)) {
                    $tokens[] = ['name' => $token['name'], 'light' => $token['light'], 'dark' => $token['dark']];
                }
            }
        }

        return $tokens;
    }

    /**
     * The document with one colour changed, everything else as it was.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function recolour(array $document, string $name, string $mode, string $value): array
    {
        $colors = is_array($document['colors'] ?? null) ? $document['colors'] : [];

        foreach ($colors as $g => $group) {
            if (!is_array($group) || !is_array($group['tokens'] ?? null)) {
                continue;
            }

            $tokens = $group['tokens'];

            foreach ($tokens as $t => $token) {
                if (is_array($token) && ($token['name'] ?? null) === $name) {
                    $tokens[$t] = [...$token, $mode => $value];
                }
            }

            $colors[$g] = [...$group, 'tokens' => $tokens];
        }

        return [...$document, 'colors' => $colors];
    }

    /** WCAG contrast between two `#rrggbb` colours; 0 for anything else. */
    private static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): ?float {
            if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) !== 1) {
                return null;
            }

            $channel = static function (string $part): float {
                $c = hexdec($part) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);
        };

        $la = $luminance($a);
        $lb = $luminance($b);

        return $la === null || $lb === null ? 0.0 : (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
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
