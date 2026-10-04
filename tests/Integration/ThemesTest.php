<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The design system saved under a name (2026-10-04): `default` until told
 * otherwise, the whole document or nothing, behind a permission of its own.
 */
#[CoversNothing]
final class ThemesTest extends DatabaseApiTestCase
{
    private const ADMIN = ['Authorization' => 'Bearer ola-token'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['sub-ola', 'ola@platform.test', 'PLATFORM_ADMIN'], ['sub-sam', 'sam@platform.test', 'SUPPORT_ADMIN']] as [$subject, $email, $role]) {
            $user = $this->connection->fetchOne(
                'INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id',
                ['s' => $subject, 'e' => $email],
            );
            self::assertIsString($user);
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(
        string $accent = '#0b6e99',
        string $second = 'scrim',
        string $stack = "'Geist Variable', ui-sans-serif, system-ui, sans-serif",
    ): array {
        return [
            'format' => 1,
            'colors' => [
                ['group' => 'Accent', 'tokens' => [
                    ['name' => 'accent', 'variable' => '--ds-accent', 'light' => $accent, 'dark' => '#4cc2ee'],
                    ['name' => $second, 'variable' => '--ds-scrim', 'light' => 'rgb(14 21 32 / 0.4)', 'dark' => 'rgb(0 0 0 / 0.6)'],
                ]],
            ],
            'fonts' => [
                ['role' => 'sans', 'variable' => '--font-sans', 'family' => 'Geist Variable', 'stack' => $stack],
            ],
            'type_scale' => [
                ['name' => 'xl', 'variable' => '--text-xl', 'size' => '1.25rem', 'line_height' => '1.65rem', 'letter_spacing' => '-0.012em'],
                ['name' => 'sm', 'variable' => '--text-sm', 'size' => '0.8125rem', 'line_height' => null, 'letter_spacing' => null],
            ],
        ];
    }

    /**
     * A value inside a decoded response, by path; null where the path leads
     * nowhere. Typed, so assertions need no offset access on mixed.
     */
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
     * @return list<string>
     */
    private function names(): array
    {
        $themes = $this->at($this->request('GET', '/api/v1/staff/themes', self::ADMIN), 'themes');
        self::assertIsArray($themes);

        return array_values(array_map(static fn (mixed $theme): string => is_array($theme) && is_string($theme['name'] ?? null) ? $theme['name'] : '', $themes));
    }

    /**
     * Nothing is seeded: `default` is absent until somebody saves it, and
     * reading it says so in its own words.
     */
    public function testDefaultStartsAbsent(): void
    {
        self::assertSame([], $this->names());

        $missing = $this->request('GET', '/api/v1/staff/themes/default', self::ADMIN);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('THEME_NOT_FOUND', $this->errorOf($missing)['code'] ?? null);
    }

    public function testSavingDefaultCreatesItThenReplacesIt(): void
    {
        $saved = $this->request('PUT', '/api/v1/staff/themes/default', self::ADMIN, $this->json(['document' => self::document()]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('default', $this->at($saved, 'theme', 'name'));
        // Read back in the order it was written, not in the order `jsonb` keeps.
        self::assertSame(self::document(), $this->at($saved, 'theme', 'document'));

        $this->request('PUT', '/api/v1/staff/themes/default', self::ADMIN, $this->json(['document' => self::document('#c0ffee')]));

        $shown = $this->request('GET', '/api/v1/staff/themes/default', self::ADMIN);
        self::assertSame('#c0ffee', $this->at($shown, 'theme', 'document', 'colors', 0, 'tokens', 0, 'light'));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM themes'));

        // The list names them and carries no document.
        $first = $this->at($this->request('GET', '/api/v1/staff/themes', self::ADMIN), 'themes', 0);
        self::assertIsArray($first);
        self::assertSame(['name', 'updated_at'], array_keys($first));
    }

    public function testAThemeHasTheNameItWasSavedUnder(): void
    {
        $this->request('PUT', '/api/v1/staff/themes/default', self::ADMIN, $this->json(['document' => self::document()]));
        $this->request('PUT', '/api/v1/staff/themes/winter-2026', self::ADMIN, $this->json(['document' => self::document('#123456')]));

        self::assertSame(['default', 'winter-2026'], $this->names());

        $refused = $this->request('PUT', '/api/v1/staff/themes/Not%20A%20Name', self::ADMIN, $this->json(['document' => self::document()]));
        self::assertSame(400, $refused->getStatusCode());
    }

    /**
     * Refused whole, and nothing written: a value that could close a CSS
     * declaration is refused now, rather than the day something reads it into
     * a stylesheet.
     */
    public function testAnythingButTheDocumentsShapeIsRefusedWhole(): void
    {
        $broken = [
            'wrong format' => [...self::document(), 'format' => 2],
            'no colours' => [...self::document(), 'colors' => []],
            'a value closing the declaration' => self::document('#fff; } body { display: none'),
            'markup in a value' => self::document('<script>'),
        ];

        $broken['a colour named twice'] = self::document(second: 'accent');
        $broken['a font stack with a semicolon'] = self::document(stack: 'Geist; color: red');

        foreach ($broken as $case => $document) {
            $response = $this->request('PUT', '/api/v1/staff/themes/default', self::ADMIN, $this->json(['document' => $document]));
            self::assertSame(400, $response->getStatusCode(), $case);
            self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null, $case);
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM themes'));
    }

    /**
     * Its own permission, PLATFORM_ADMIN alone — asserted against the
     * catalogue, for the reason `SignUpSettingsTest` gives: a grant written
     * into reference data would stay there for every test after.
     */
    public function testItAnswersToItsOwnPermission(): void
    {
        $sam = ['Authorization' => 'Bearer sam-token'];

        self::assertSame(403, $this->request('GET', '/api/v1/staff/themes', $sam)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/staff/themes/default', $sam)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/staff/themes/default', $sam, $this->json(['document' => self::document()]))->getStatusCode());
        self::assertSame(401, $this->request('PUT', '/api/v1/staff/themes/default', [], $this->json(['document' => self::document()]))->getStatusCode());

        self::assertSame(['PLATFORM_ADMIN'], $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT r.code
                  FROM platform_role_permissions rp
                  JOIN platform_roles r ON r.id = rp.platform_role_id
                  JOIN platform_permissions p ON p.id = rp.platform_permission_id
                 WHERE p.code = 'staff.design.manage'
                 ORDER BY r.code
                SQL,
        ));
    }
}
