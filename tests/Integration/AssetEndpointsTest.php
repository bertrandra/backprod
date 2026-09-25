<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Job\Service\JobRunner;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Project\Service\ProjectWorkspace;
use App\Storage\Domain\StorageProvider;
use App\Storage\Infrastructure\LocalStorageProvider;
use App\Storage\Service\AssetLinks;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * §15 and non-negotiable #9, end to end.
 *
 * The storage provider is the real local one writing to a real temporary
 * directory, because "the bytes are not in PostgreSQL" is the claim under
 * test and an in-memory store would make it true by construction.
 */
#[CoversNothing]
final class AssetEndpointsTest extends DatabaseApiTestCase
{
    private const SECRET = 'test-link-secret';

    /** A real 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $product = '';
    private string $tenant = '';
    private string $user = '';
    private string $project = '';
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/backprod-assets-' . bin2hex(random_bytes(6));

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->user = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );
        $this->project = $this->id(
            <<<'SQL'
                INSERT INTO projects (tenant_id, product_id, name, schema_version, document, created_by)
                VALUES (:tenant, :product, 'Roof survey', 1, CAST('{"walls": 4}' AS jsonb), :user)
                RETURNING id
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'user' => $this->user],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['mia-token' => 'sub-mia']),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),

            StorageProvider::class => new LocalStorageProvider($this->root),
            AssetLinks::class => new AssetLinks(self::SECRET),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->user,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['projects.read', 'projects.write', 'assets.read', 'assets.manage', 'jobs.read', 'jobs.manage'],
                ),
            ]),

            // Exporting a project goes through `ProjectRoute`, which since
            // 2026-09-25 asks whether a subscription covers this person
            // before it asks what their role allows (ADR-053). The project
            // above is INSERTed rather than bought, so without this the
            // tenant has bought nothing and Mia may reach none of it —
            // a true refusal, and not what these tests are about.
            EntitlementRepository::class => InMemoryEntitlementRepository::granting([
                $this->tenant . ':' . $this->product => [ProjectWorkspace::QUOTA],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            self::removeTree($this->root);
        }

        parent::tearDown();
    }

    // --- Upload --------------------------------------------------------------

    public function testAnUploadIsDescribedByItsBytesNotByWhatTheRequestClaimed(): void
    {
        // Announced as a PDF; it is a PNG, and that is what gets stored.
        $response = $this->upload($this->png(), 'survey.png', 'application/pdf');

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('image/png', $body['content_type'] ?? null);
        self::assertSame(hash('sha256', $this->png()), $body['checksum'] ?? null);
    }

    public function testTheBytesAreNotInPostgres(): void
    {
        $this->upload($this->png(), 'survey.png');

        // The exit criterion, stated as a query: the row describes an object,
        // it does not hold one.
        $columns = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT column_name FROM information_schema.columns
                 WHERE table_name = 'assets' AND data_type IN ('bytea', 'text')
                SQL,
        );

        self::assertNotContains('contents', $columns);
        self::assertSame(1, $this->countFilesUnder($this->root));
    }

    public function testAScriptWearingImageMagicBytesIsRefused(): void
    {
        $response = $this->upload("\x89PNG\r\n\x1a\n" . '<?php system($_GET["c"]); ?>', 'nice.png', 'image/png');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('UPLOAD_TYPE_REJECTED', $this->errorOf($response)['code'] ?? null);
        // Nothing reached the store either.
        self::assertSame(0, $this->countFilesUnder($this->root));
    }

    public function testAFilenameCannotEscapeIntoAPath(): void
    {
        $response = $this->upload($this->png(), '../../etc/passwd');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('etcpasswd', $this->decode($response)['filename'] ?? null);

        // And the object was addressed by a generated key regardless.
        $key = $this->connection->fetchOne('SELECT storage_key FROM assets LIMIT 1');
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
    }

    // --- Download ------------------------------------------------------------

    public function testASignedLinkFetchesTheBytesWithoutASession(): void
    {
        $assetId = $this->uploadedId();
        $url = $this->mintLink($assetId);

        // No Authorization header at all — which is the point: a browser
        // following a download link sends none.
        $response = $this->request('GET', $url, []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->png(), (string) $response->getBody());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        // Never rendered inline from this platform's own origin.
        self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAnUnsignedDownloadIsRefused(): void
    {
        $assetId = $this->uploadedId();

        $response = $this->request('GET', '/api/v1/downloads/' . $assetId . '/content', []);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnExtendedExpiryIsRefused(): void
    {
        $assetId = $this->uploadedId();
        $url = $this->mintLink($assetId);

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = $query['expires'] ?? '';
        $signature = $query['signature'] ?? '';
        self::assertIsString($expires);
        self::assertIsString($signature);

        $tampered = sprintf(
            '/api/v1/downloads/%s/content?expires=%d&signature=%s',
            $assetId,
            (int) $expires + 3600,
            $signature,
        );

        self::assertSame(403, $this->request('GET', $tampered, [])->getStatusCode());
    }

    // --- Isolation -----------------------------------------------------------

    public function testAnAssetIsNotReadableFromAnotherTenant(): void
    {
        $assetId = $this->uploadedId();

        $other = $this->id("INSERT INTO tenants (name, slug) VALUES ('Beta', 'beta') RETURNING id");
        $this->connection->executeStatement(
            'UPDATE assets SET tenant_id = :tenant WHERE id = :id',
            ['tenant' => $other, 'id' => $assetId],
        );

        self::assertSame(404, $this->get('/api/v1/assets/' . $assetId)->getStatusCode());
    }

    public function testDeletingAnAssetRemovesTheObjectToo(): void
    {
        $assetId = $this->uploadedId();
        self::assertSame(1, $this->countFilesUnder($this->root));

        $response = $this->request(
            'DELETE',
            '/api/v1/assets/' . $assetId,
            ['Authorization' => 'Bearer mia-token', 'X-Product' => 'atlas'],
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->countFilesUnder($this->root));
    }

    // --- Export: where M7's two halves meet ----------------------------------

    public function testAnExportReturnsAJobAndProducesAnAssetWhenTheRunnerRuns(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/projects/' . $this->project . '/exports',
            ['Authorization' => 'Bearer mia-token', 'X-Product' => 'atlas'],
        );

        // 202: the request never rendered anything itself.
        self::assertSame(202, $response->getStatusCode());

        $jobId = $this->decode($response)['id'] ?? null;
        self::assertIsString($jobId);
        self::assertSame('QUEUED', $this->decode($response)['status'] ?? null);

        // Nothing has run yet, so there is no asset.
        self::assertSame(0, $this->countAssets());

        $runner = $this->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        $runner->runOnce();

        $finished = $this->decode($this->get('/api/v1/jobs/' . $jobId));
        self::assertSame('SUCCEEDED', $finished['status'] ?? null);

        $result = $finished['result'] ?? null;
        self::assertIsArray($result);

        $exportedId = $result['asset_id'] ?? null;
        self::assertIsString($exportedId);

        $asset = $this->decode($this->get('/api/v1/assets/' . $exportedId));
        self::assertSame('EXPORT', $asset['kind'] ?? null);
        self::assertSame('application/json', $asset['content_type'] ?? null);
    }

    public function testAskingForTheSameExportTwiceQueuesItOnce(): void
    {
        $first = $this->decode($this->requestExport())['id'] ?? null;
        $second = $this->decode($this->requestExport())['id'] ?? null;

        self::assertSame($first, $second);
        self::assertSame(1, $this->countJobs());
    }

    // --- Helpers -------------------------------------------------------------

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    private function upload(string $bytes, string $filename, string $claimedType = 'image/png'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/projects/' . $this->project . '/assets',
            [
                'Authorization' => 'Bearer mia-token',
                'X-Product' => 'atlas',
                'X-Filename' => $filename,
                'Content-Type' => $claimedType,
            ],
            $bytes,
        );
    }

    private function uploadedId(): string
    {
        $id = $this->decode($this->upload($this->png(), 'survey.png'))['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    private function mintLink(string $assetId): string
    {
        $response = $this->request(
            'POST',
            '/api/v1/assets/' . $assetId . '/link',
            ['Authorization' => 'Bearer mia-token', 'X-Product' => 'atlas'],
        );

        self::assertSame(201, $response->getStatusCode());

        $url = $this->decode($response)['url'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    private function requestExport(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/projects/' . $this->project . '/exports',
            ['Authorization' => 'Bearer mia-token', 'X-Product' => 'atlas'],
        );
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer mia-token', 'X-Product' => 'atlas']);
    }

    private function countAssets(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM assets');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function countJobs(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM jobs');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function countFilesUnder(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }

        $found = 0;

        foreach (self::filesIn($root) as $ignored) {
            ++$found;
        }

        return $found;
    }

    /**
     * @return iterable<string>
     */
    private static function filesIn(string $directory): iterable
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                yield from self::filesIn($path);

                continue;
            }

            yield $path;
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : unlink($path);
        }

        rmdir($directory);
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
