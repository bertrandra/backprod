<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\Theme\Domain\ThemeDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * An organisation's themes (2026-10-04): five templates nobody edits in
 * place, copies an administrator saves in their own organisation, and the one
 * its members' screens wear.
 */
#[CoversNothing]
final class TenantThemesTest extends DatabaseApiTestCase
{
    private const TEMPLATES = ['petrol-classic', 'forest-ledger', 'terracotta-studio', 'midnight-indigo', 'graphite-compact'];

    /**
     * The pairs text is set in, foreground on ground — the same list the
     * console's Pairings table and `tone.ts` rely on.
     */
    private const PAIRS = [
        ['ink', 'canvas'], ['ink', 'surface'], ['ink', 'well'], ['ink', 'raised'], ['ink', 'accent-wash'],
        ['muted', 'canvas'], ['muted', 'surface'], ['muted', 'well'],
        ['subtle', 'canvas'], ['subtle', 'surface'],
        ['accent', 'surface'], ['accent', 'canvas'], ['on-accent', 'accent'], ['accent-strong', 'accent-wash'],
        ['on-inverse', 'inverse'],
        ['success', 'success-wash'], ['warning', 'warning-wash'], ['danger', 'danger-wash'], ['info', 'info-wash'],
        ['muted', 'success-wash'], ['muted', 'warning-wash'], ['muted', 'danger-wash'], ['muted', 'info-wash'],
    ];

    private string $product = '';
    private string $tenant = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $other = $this->id("INSERT INTO tenants (name, slug) VALUES ('Basic', 'basic') RETURNING id");
        $ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $raj = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-raj', 'raj@acme.test') RETURNING id");
        $bo = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-bo', 'bo@basic.test') RETURNING id");

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ada-token' => 'sub-ada', 'raj-token' => 'sub-raj', 'bo-token' => 'sub-bo']),
            ProductRepository::class => new InMemoryProductRepository([new Product($this->product, 'atlas', 'Atlas', true)]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $ada, $this->product, ['TENANT_ADMIN'], ['skin.manage']),
                new TenantMembership($this->tenant, $raj, $this->product, ['USER'], []),
                // Another organisation's administrator, on an offer selling
                // no white label: the permission alone is what themes ask.
                new TenantMembership($other, $bo, $this->product, ['TENANT_ADMIN'], ['skin.manage']),
            ]),
            EntitlementRepository::class => InMemoryEntitlementRepository::granting([]),
        ]);
    }

    public function testFiveTemplatesAreOfferedInOrderEachAThemeInFull(): void
    {
        $templates = $this->at($this->call('GET', '/api/v1/tenant/theme-templates'), 'templates');
        self::assertIsArray($templates);
        self::assertSame(self::TEMPLATES, array_column($templates, 'name'));

        foreach ($templates as $template) {
            self::assertIsArray($template);
            $document = $template['document'] ?? null;
            self::assertIsArray($document);
            // Each passes the shape a save is held to, and says what it is.
            self::assertSame($document, ThemeDocument::fromArray($document)->toArray());
            self::assertIsString($document['label'] ?? null);
            self::assertIsString($document['description'] ?? null);
        }
    }

    /**
     * An organisation's screens wear what it chooses, so what it is offered
     * has to be readable: every pair text is set in clears WCAG AA, in both
     * themes, in every template.
     */
    public function testEveryTemplateClearsAaOnEveryPairInBothThemes(): void
    {
        $templates = $this->at($this->call('GET', '/api/v1/tenant/theme-templates'), 'templates');
        self::assertIsArray($templates);
        $failing = [];

        foreach ($templates as $template) {
            self::assertIsArray($template);
            $values = [];

            foreach ($this->tokens($template['document'] ?? null) as $token) {
                $values[$token['name']] = $token;
            }

            foreach (['light', 'dark'] as $theme) {
                foreach (self::PAIRS as [$fg, $bg]) {
                    $ratio = self::contrast($values[$fg][$theme] ?? '', $values[$bg][$theme] ?? '');

                    if ($ratio < 4.5) {
                        $failing[] = sprintf('%s %s: %s on %s is %.2f', is_string($template['name'] ?? null) ? $template['name'] : '?', $theme, $fg, $bg, $ratio);
                    }
                }
            }
        }

        self::assertSame([], $failing);
    }

    public function testAnAdministratorSavesACopyAndTheTemplateDoesNotMove(): void
    {
        $forest = $this->template('forest-ledger');
        $copy = [...self::recolour($forest, 'accent', 'light', '#14532d'), 'label' => 'Acme forest'];

        // Saved under the template's own name, in the organisation: a copy.
        $saved = $this->call('PUT', '/api/v1/tenant/themes/forest-ledger', ['document' => $copy]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('#14532d', $this->at($saved, 'theme', 'document', 'colors', 3, 'tokens', 0, 'light'));
        self::assertFalse($this->at($saved, 'theme', 'active'));

        self::assertSame($forest, $this->template('forest-ledger'));
        self::assertSame(['forest-ledger'], $this->names());
        self::assertSame('Acme forest', $this->at($this->call('GET', '/api/v1/tenant/themes/forest-ledger'), 'theme', 'document', 'label'));
    }

    public function testOneThemeIsActiveAndEveryMemberReadsIt(): void
    {
        $this->call('PUT', '/api/v1/tenant/themes/day', ['document' => $this->template('petrol-classic')]);
        $this->call('PUT', '/api/v1/tenant/themes/night', ['document' => $this->template('midnight-indigo')]);

        // Nothing chosen: the platform's own design.
        self::assertNull($this->at($this->call('GET', '/api/v1/tenant/theme', null, 'raj-token'), 'theme'));

        self::assertSame('day', $this->at($this->call('PUT', '/api/v1/tenant/theme', ['name' => 'day']), 'theme', 'name'));
        self::assertSame('night', $this->at($this->call('PUT', '/api/v1/tenant/theme', ['name' => 'night']), 'theme', 'name'));

        // A member who may configure nothing still reads what their screens wear.
        $worn = $this->call('GET', '/api/v1/tenant/theme', null, 'raj-token');
        self::assertSame(200, $worn->getStatusCode());
        self::assertSame('night', $this->at($worn, 'theme', 'name'));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM tenant_themes WHERE active'));

        // An unknown name changes nothing, and says so.
        $unknown = $this->call('PUT', '/api/v1/tenant/theme', ['name' => 'dusk']);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('night', $this->at($this->call('GET', '/api/v1/tenant/theme'), 'theme', 'name'));

        // Null goes back to the platform's design; a body without `name` is not that.
        self::assertSame(400, $this->call('PUT', '/api/v1/tenant/theme', ['nom' => null])->getStatusCode());
        self::assertNull($this->at($this->call('PUT', '/api/v1/tenant/theme', ['name' => null]), 'theme'));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_themes WHERE active'));
    }

    /**
     * Tenant isolation: another organisation's administrator holds the same
     * permission, and sees, reads and activates none of this one's themes —
     * not refused but not found, which is the same answer as no such theme.
     */
    public function testAnotherOrganisationSeesNoneOfIt(): void
    {
        $this->call('PUT', '/api/v1/tenant/themes/acme-only', ['document' => $this->template('forest-ledger')]);
        $this->call('PUT', '/api/v1/tenant/theme', ['name' => 'acme-only']);

        self::assertSame([], $this->names('bo-token'));
        self::assertSame(404, $this->call('GET', '/api/v1/tenant/themes/acme-only', null, 'bo-token')->getStatusCode());
        self::assertSame(404, $this->call('PUT', '/api/v1/tenant/theme', ['name' => 'acme-only'], 'bo-token')->getStatusCode());
        self::assertNull($this->at($this->call('GET', '/api/v1/tenant/theme', null, 'bo-token'), 'theme'));

        // And on an offer with no white label, the permission is enough to theme its own.
        self::assertSame(200, $this->call('PUT', '/api/v1/tenant/themes/basic', ['document' => $this->template('graphite-compact')], 'bo-token')->getStatusCode());
    }

    public function testAMemberWithoutThePermissionConfiguresNothing(): void
    {
        foreach ([
            ['GET', '/api/v1/tenant/theme-templates', null],
            ['GET', '/api/v1/tenant/themes', null],
            ['GET', '/api/v1/tenant/themes/day', null],
            ['PUT', '/api/v1/tenant/themes/day', ['document' => $this->template('petrol-classic')]],
            ['PUT', '/api/v1/tenant/theme', ['name' => null]],
        ] as [$method, $path, $body]) {
            $response = $this->call($method, $path, $body, 'raj-token');
            self::assertSame(403, $response->getStatusCode(), "{$method} {$path}");
            self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_themes'));
    }

    public function testADocumentOutOfShapeIsRefusedWhole(): void
    {
        $broken = self::recolour($this->template('terracotta-studio'), 'canvas', 'dark', '#000; } body { display: none');

        $response = $this->call('PUT', '/api/v1/tenant/themes/broken', ['document' => $broken]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM tenant_themes'));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null, string $token = 'ada-token'): ResponseInterface
    {
        return $this->request($method, $path, ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'], $body === null ? null : $this->json($body));
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
     * @return array<string, mixed>
     */
    private function template(string $name): array
    {
        $templates = $this->at($this->call('GET', '/api/v1/tenant/theme-templates'), 'templates');
        self::assertIsArray($templates);

        foreach ($templates as $template) {
            if (is_array($template) && ($template['name'] ?? null) === $name && is_array($template['document'] ?? null)) {
                /** @var array<string, mixed> $document */
                $document = $template['document'];

                return $document;
            }
        }

        self::fail("No template {$name}.");
    }

    /**
     * @return list<string>
     */
    private function names(string $token = 'ada-token'): array
    {
        $themes = $this->at($this->call('GET', '/api/v1/tenant/themes', null, $token), 'themes');
        self::assertIsArray($themes);

        return array_values(array_map(static fn (mixed $theme): string => is_array($theme) && is_string($theme['name'] ?? null) ? $theme['name'] : '', $themes));
    }

    /**
     * @return list<array{name: string, light: string, dark: string}>
     */
    private function tokens(mixed $document): array
    {
        $tokens = [];

        foreach (is_array($document) && is_array($document['colors'] ?? null) ? $document['colors'] : [] as $group) {
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
    private static function recolour(array $document, string $name, string $theme, string $value): array
    {
        $colors = is_array($document['colors'] ?? null) ? $document['colors'] : [];

        foreach ($colors as $g => $group) {
            if (!is_array($group) || !is_array($group['tokens'] ?? null)) {
                continue;
            }

            $tokens = $group['tokens'];

            foreach ($tokens as $t => $token) {
                if (is_array($token) && ($token['name'] ?? null) === $name) {
                    $tokens[$t] = [...$token, $theme => $value];
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

        if ($la === null || $lb === null) {
            return 0.0;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
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
