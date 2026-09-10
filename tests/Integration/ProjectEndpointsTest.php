<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRegistry;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRegistry;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Project\Domain\DocumentPolicy;
use App\Project\Domain\ProjectRepository;
use App\Project\Infrastructure\InMemoryProjectRepository;
use App\Project\Service\ProjectWorkspace;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserDirectory;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\InMemoryUserDirectory;
use App\User\Infrastructure\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The project endpoints through the real pipeline.
 *
 * M4's two non-negotiables are here: a project declares a schema version
 * (#10) and a project document is not where assets live (#9). Both are
 * refusals, and a refusal nobody has watched happen is an assumption.
 */
#[CoversNothing]
final class ProjectEndpointsTest extends ApiTestCase
{
    private const ALICE = 'user-alice';
    private const BOB = 'user-bob';
    private const CAROL = 'user-carol';

    private const ATLAS = 'prod-atlas';

    private const ACME = 'tenant-acme';
    private const GLOBEX = 'tenant-globex';

    protected function setUp(): void
    {
        parent::setUp();

        $atlas = new Product(self::ATLAS, 'atlas', 'Atlas', true);

        $this->override([
            UserDirectory::class => new InMemoryUserDirectory(),

            UserRepository::class => new InMemoryUserRepository([
                new PlatformUser(self::ALICE, self::ALICE, 'alice@example.test'),
                new PlatformUser(self::BOB, self::BOB, 'bob@example.test'),
                new PlatformUser(self::CAROL, self::CAROL, 'carol@example.test'),
            ]),

            AuthProvider::class => new FakeAuthProvider([
                'alice-token' => self::ALICE,
                'bob-token' => self::BOB,
                'carol-token' => self::CAROL,
            ]),

            ProductRepository::class => new InMemoryProductRepository([$atlas]),

            // Atlas accepts two document schema versions. Tests that need a
            // different answer swap this definition: the container is rebuilt
            // per request, so configuration can change mid-test the way it
            // would change in production.
            ProductRegistry::class => self::registryAccepting([1, 2]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    self::ACME,
                    self::ALICE,
                    self::ATLAS,
                    ['TENANT_ADMIN'],
                    ['projects.read', 'projects.write'],
                ),
                new TenantMembership(
                    self::GLOBEX,
                    self::BOB,
                    self::ATLAS,
                    ['TENANT_ADMIN'],
                    ['projects.read', 'projects.write'],
                ),
                // Carol may look, not touch.
                new TenantMembership(self::ACME, self::CAROL, self::ATLAS, ['USER'], ['projects.read']),
            ]),

            ProjectRepository::class => new InMemoryProjectRepository(),

            // A tenant with no subscription may hold no projects, so these
            // tests grant the entitlement that says they may. How *many* is
            // what the quota tests are about, not these.
            EntitlementRepository::class => InMemoryEntitlementRepository::granting([
                self::ACME . ':' . self::ATLAS => [ProjectWorkspace::QUOTA],
                self::GLOBEX . ':' . self::ATLAS => [ProjectWorkspace::QUOTA],
            ]),
        ]);
    }

    // --- Non-negotiable #10: a project has a schema version -----------------

    public function testAProjectWithoutASchemaVersionIsRefused(): void
    {
        $response = $this->create(['name' => 'Garden', 'document' => ['walls' => []]]);

        // 422, not 400: the body was readable. What it describes is not a
        // project the platform can store.
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('SCHEMA_VERSION_REQUIRED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnUnsupportedSchemaVersionIsRefused(): void
    {
        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 99,
            'document' => ['walls' => []],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $error = $this->errorOf($response);
        self::assertSame('UNSUPPORTED_SCHEMA_VERSION', $error['code'] ?? null);

        // The answer to the caller's next question, and nothing they could
        // not already read from their own product's configuration.
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame([1, 2], $details['supported'] ?? null);
    }

    /**
     * A product that has declared no accepted schema versions accepts no
     * documents — it does not accept everything. Absence of configuration
     * must fail closed, the same way absence of a subscription grants
     * nothing.
     */
    public function testAProductThatDeclaredNoSchemaVersionsAcceptsNone(): void
    {
        $this->override([ProductRegistry::class => new InMemoryProductRegistry()]);

        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 1,
            'document' => ['walls' => []],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $details = $this->errorOf($response)['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame([], $details['supported'] ?? null);
    }

    /**
     * Retiring a schema stops new content being written under it. It must not
     * stop a customer relabelling work they already have while they migrate.
     */
    public function testRetiringASchemaBlocksEditsButNotRenames(): void
    {
        $project = $this->createdProject();

        $this->override([ProductRegistry::class => self::registryAccepting([2])]);

        $rename = $this->patch($project, ['name' => 'Renamed']);
        self::assertSame(200, $rename->getStatusCode());
        self::assertSame('Renamed', $this->decode($rename)['name'] ?? null);

        $edit = $this->patch($project, ['document' => ['walls' => ['north']]]);
        self::assertSame(422, $edit->getStatusCode());
        self::assertSame('UNSUPPORTED_SCHEMA_VERSION', $this->errorOf($edit)['code'] ?? null);
    }

    // --- Non-negotiable #9: assets are not document content -----------------

    public function testAnEmbeddedDataUriIsRefused(): void
    {
        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 1,
            'document' => ['layers' => [['texture' => 'data:image/png;base64,iVBORw0KGgo=']]],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $error = $this->errorOf($response);
        self::assertSame('EMBEDDED_ASSET_REJECTED', $error['code'] ?? null);

        // The path, so a client can find it without bisecting its document.
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('layers/0/texture', $details['path'] ?? null);
    }

    /**
     * Size alone would let a small embedded asset through, and a client that
     * learns the rule on a thumbnail will not discover it later on a mesh.
     */
    public function testATinyDataUriIsStillRefused(): void
    {
        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 1,
            'document' => ['icon' => 'data:,x'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('EMBEDDED_ASSET_REJECTED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAStringLongerThanTheLimitIsRefused(): void
    {
        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 1,
            'document' => ['blob' => str_repeat('x', DocumentPolicy::MAX_STRING_BYTES + 1)],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('EMBEDDED_ASSET_REJECTED', $this->errorOf($response)['code'] ?? null);
    }

    /**
     * Many legal-sized strings that add up to more than the platform stores
     * inline: refused on the total, with a different status, because the fix
     * is different too.
     */
    public function testADocumentLargerThanTheLimitIsRefused(): void
    {
        $chunk = str_repeat('x', 60_000);
        $document = [];

        for ($i = 0; $i < 20; ++$i) {
            $document['chunk' . $i] = $chunk;
        }

        $response = $this->create([
            'name' => 'Garden',
            'schema_version' => 1,
            'document' => $document,
        ]);

        self::assertSame(413, $response->getStatusCode());

        $error = $this->errorOf($response);
        self::assertSame('PAYLOAD_TOO_LARGE', $error['code'] ?? null);

        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame(DocumentPolicy::MAX_DOCUMENT_BYTES, $details['limit_bytes'] ?? null);
    }

    // --- The document is the Core's, and comes back unchanged ---------------

    public function testCreatingAProjectReturnsItWithItsDocument(): void
    {
        $response = $this->create([
            'name' => '  Garden  ',
            'description' => 'Back garden',
            'schema_version' => 1,
            'document' => ['walls' => ['north', 'south'], 'scale' => 100],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('Garden', $body['name'] ?? null, 'the name is trimmed');
        self::assertSame('Back garden', $body['description'] ?? null);
        self::assertSame(1, $body['schema_version'] ?? null);
        self::assertSame(self::ALICE, $body['created_by'] ?? null);
        self::assertSame(
            ['walls' => ['north', 'south'], 'scale' => 100],
            $body['document'] ?? null,
        );

        // Context, not content: the caller already knows both, and echoing
        // them invites clients to treat them as something they may vary.
        self::assertArrayNotHasKey('tenant_id', $body);
        self::assertArrayNotHasKey('product_id', $body);
    }

    /**
     * The failure this shape is chosen to prevent.
     *
     * A document keyed by numeric ids — {"0": …} — is a JSON object, but
     * decoding it into a PHP array and encoding it back yields ["…"], an
     * array. The project would come back a different shape than it went in,
     * with nothing failing anywhere.
     */
    public function testAnObjectKeyedByNumbersStaysAnObject(): void
    {
        $body = <<<'JSON'
            {
                "name": "Garden",
                "schema_version": 1,
                "document": {"layers": {"0": {"kind": "wall"}, "1": {"kind": "roof"}}}
            }
            JSON;

        $response = $this->request('POST', '/api/v1/projects', $this->aliceHeaders(), $body);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString(
            '"layers":{"0":{"kind":"wall"},"1":{"kind":"roof"}}',
            (string) $response->getBody(),
        );
    }

    // --- Isolation and permissions ------------------------------------------

    /**
     * Bob's project is in another tenant. Alice must get the same answer she
     * would get for an id nobody ever issued.
     */
    public function testAProjectInAnotherTenantIsNotFound(): void
    {
        $bobs = $this->decode($this->create(
            ['name' => 'Bobs', 'schema_version' => 1, 'document' => (object) []],
            'bob-token',
        ));

        $id = $bobs['id'] ?? null;
        self::assertIsString($id);

        $response = $this->request('GET', '/api/v1/projects/' . $id, $this->aliceHeaders());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PROJECT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);

        $invented = $this->request(
            'GET',
            '/api/v1/projects/2f1c1c8e-0000-4000-8000-000000000000',
            $this->aliceHeaders(),
        );
        self::assertSame($invented->getStatusCode(), $response->getStatusCode());
        self::assertSame(
            $this->errorOf($invented)['code'] ?? null,
            $this->errorOf($response)['code'] ?? null,
        );
    }

    public function testAMalformedProjectIdIsNotFoundRatherThanAnError(): void
    {
        $response = $this->request('GET', '/api/v1/projects/not-a-uuid', $this->aliceHeaders());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PROJECT_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testReadingIsNotWriting(): void
    {
        $project = $this->createdProject();

        $read = $this->request('GET', '/api/v1/projects/' . $project, $this->headersFor('carol-token'));
        self::assertSame(200, $read->getStatusCode());

        $write = $this->request(
            'PATCH',
            '/api/v1/projects/' . $project,
            $this->headersFor('carol-token'),
            $this->json(['name' => 'Carols']),
        );

        self::assertSame(403, $write->getStatusCode());

        $error = $this->errorOf($write);
        self::assertSame('PERMISSION_DENIED', $error['code'] ?? null);

        // A role change, not a subscription change — the details say which.
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('projects.write', $details['permission'] ?? null);
    }

    // --- Updating -----------------------------------------------------------

    public function testAPatchChangesOnlyWhatItMentions(): void
    {
        $project = $this->createdProject();

        $response = $this->patch($project, ['name' => 'Renamed']);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('Renamed', $body['name'] ?? null);
        self::assertSame('Back garden', $body['description'] ?? null);
        self::assertSame(['walls' => ['north']], $body['document'] ?? null);
    }

    public function testADescriptionCanBeCleared(): void
    {
        $project = $this->createdProject();

        $response = $this->patch($project, ['description' => null]);

        self::assertSame(200, $response->getStatusCode());

        // Both halves matter, and `?? 'unset'` would defeat them: it replaces
        // the null this is testing for with a string, so the assertion could
        // never hold. The key must be present, and its value must be null.
        $body = $this->decode($response);
        self::assertArrayHasKey('description', $body);
        self::assertNull($body['description']);
    }

    public function testAPatchThatChangesNothingIsRefused(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/projects/' . $this->createdProject(),
            $this->aliceHeaders(),
            '{}',
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('NOTHING_TO_UPDATE', $this->errorOf($response)['code'] ?? null);
    }

    // --- Versions -----------------------------------------------------------

    public function testSnapshotsAreNumberedAndListedNewestFirst(): void
    {
        $project = $this->createdProject();

        $first = $this->decode($this->snapshot($project, 'before'));
        $this->patch($project, ['document' => ['walls' => ['north', 'south']]]);
        $second = $this->decode($this->snapshot($project, 'after'));

        self::assertSame(1, $first['version_number'] ?? null);
        self::assertSame(2, $second['version_number'] ?? null);

        $listed = $this->decode(
            $this->request('GET', '/api/v1/projects/' . $project . '/versions', $this->aliceHeaders()),
        )['versions'] ?? null;

        self::assertIsArray($listed);
        self::assertCount(2, $listed);
        self::assertSame([2, 1], array_map(
            static fn (mixed $v): mixed => is_array($v) ? ($v['version_number'] ?? null) : null,
            $listed,
        ));

        // A listing is for choosing a version, not for downloading two of
        // them; the documents are served one at a time.
        self::assertIsArray($listed[0]);
        self::assertArrayNotHasKey('document', $listed[0]);
    }

    public function testRestoringPutsTheEarlierDocumentBack(): void
    {
        $project = $this->createdProject();
        $version = $this->decode($this->snapshot($project, 'original'));

        $versionId = $version['id'] ?? null;
        self::assertIsString($versionId);

        $this->patch($project, ['document' => ['walls' => ['north', 'south', 'east']]]);

        $restored = $this->request(
            'POST',
            '/api/v1/projects/' . $project . '/restore',
            $this->aliceHeaders(),
            $this->json(['version_id' => $versionId]),
        );

        self::assertSame(200, $restored->getStatusCode());
        self::assertSame(['walls' => ['north']], $this->decode($restored)['document'] ?? null);
    }

    /**
     * Restoring is the one operation that overwrites a project, so it is also
     * the one that captures what it is about to overwrite. Undoing a restore
     * means restoring the version the restore itself created.
     */
    public function testRestoringCapturesTheStateItOverwrites(): void
    {
        $project = $this->createdProject();
        $original = $this->decode($this->snapshot($project, 'original'));

        $originalId = $original['id'] ?? null;
        self::assertIsString($originalId);

        $this->patch($project, ['document' => ['walls' => ['north', 'south', 'east']]]);

        $this->request(
            'POST',
            '/api/v1/projects/' . $project . '/restore',
            $this->aliceHeaders(),
            $this->json(['version_id' => $originalId]),
        );

        $versions = $this->decode(
            $this->request('GET', '/api/v1/projects/' . $project . '/versions', $this->aliceHeaders()),
        )['versions'] ?? null;

        self::assertIsArray($versions);
        self::assertCount(2, $versions, 'the restore recorded what it replaced');

        $newest = $versions[0];
        self::assertIsArray($newest);

        $newestId = $newest['id'] ?? null;
        self::assertIsString($newestId);

        $captured = $this->decode($this->request(
            'GET',
            '/api/v1/projects/' . $project . '/versions/' . $newestId,
            $this->aliceHeaders(),
        ));

        self::assertSame(
            ['walls' => ['north', 'south', 'east']],
            $captured['document'] ?? null,
            'the pre-restore snapshot holds the state that was overwritten',
        );
    }

    public function testAnUnknownVersionIsReportedAsSuch(): void
    {
        $project = $this->createdProject();

        $response = $this->request(
            'GET',
            '/api/v1/projects/' . $project . '/versions/2f1c1c8e-0000-4000-8000-000000000000',
            $this->aliceHeaders(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('PROJECT_VERSION_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    // --- Duplicating and deleting -------------------------------------------

    public function testDuplicatingCopiesTheDocumentAndStartsAFreshHistory(): void
    {
        $project = $this->createdProject();
        $this->snapshot($project, 'original');

        $response = $this->request(
            'POST',
            '/api/v1/projects/' . $project . '/duplicate',
            $this->aliceHeaders(),
        );

        self::assertSame(201, $response->getStatusCode());

        $copy = $this->decode($response);
        self::assertNotSame($project, $copy['id'] ?? null);
        self::assertSame('Garden (copy)', $copy['name'] ?? null);
        self::assertSame(['walls' => ['north']], $copy['document'] ?? null);

        $copyId = $copy['id'] ?? null;
        self::assertIsString($copyId);

        $versions = $this->decode(
            $this->request('GET', '/api/v1/projects/' . $copyId . '/versions', $this->aliceHeaders()),
        )['versions'] ?? null;

        self::assertSame([], $versions, 'the copy did not inherit the original\'s history');
    }

    public function testDeletingAProjectRemovesItAndItsVersions(): void
    {
        $project = $this->createdProject();
        $this->snapshot($project, 'original');

        $deleted = $this->request('DELETE', '/api/v1/projects/' . $project, $this->aliceHeaders());
        self::assertSame(204, $deleted->getStatusCode());

        $after = $this->request('GET', '/api/v1/projects/' . $project, $this->aliceHeaders());
        self::assertSame(404, $after->getStatusCode());

        $versions = $this->request(
            'GET',
            '/api/v1/projects/' . $project . '/versions',
            $this->aliceHeaders(),
        );
        self::assertSame(404, $versions->getStatusCode());
    }

    // --- Listing ------------------------------------------------------------

    public function testListingIsScopedToTheTenantAndPaged(): void
    {
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->create(['name' => $name, 'schema_version' => 1, 'document' => (object) []]);
        }

        $this->create(['name' => 'Bobs', 'schema_version' => 1, 'document' => (object) []], 'bob-token');

        $all = $this->decode($this->request('GET', '/api/v1/projects', $this->aliceHeaders()));
        self::assertSame(3, $all['total'] ?? null, 'Bob\'s project belongs to another tenant');

        $page = $this->decode(
            $this->request('GET', '/api/v1/projects?limit=2&offset=0', $this->aliceHeaders()),
        );

        $projects = $page['projects'] ?? null;
        self::assertIsArray($projects);
        self::assertCount(2, $projects);
        self::assertSame(3, $page['total'] ?? null, 'the total counts what exists, not what was returned');

        // A listing is not a bulk download of documents.
        self::assertIsArray($projects[0]);
        self::assertArrayNotHasKey('document', $projects[0]);
    }

    public function testAnOutOfRangeLimitIsRefusedRatherThanClamped(): void
    {
        $response = $this->request('GET', '/api/v1/projects?limit=5000', $this->aliceHeaders());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    // --- R13: deletion is recoverable ----------------------------------------

    /**
     * The whole of R13, through the API a client actually uses.
     *
     * Delete, confirm it is out of the live list and in the bin with a date,
     * confirm its versions are still there, put it back, and confirm the
     * document came back untouched.
     */
    public function testADeletedProjectIsInTheBinAndComesBackWithItsHistory(): void
    {
        $projectId = $this->createdProject();
        $this->snapshot($projectId, 'before deleting');

        $deleted = $this->request('DELETE', '/api/v1/projects/' . $projectId, $this->aliceHeaders());
        self::assertSame(204, $deleted->getStatusCode());

        // Out of the live list.
        $live = $this->decode($this->request('GET', '/api/v1/projects', $this->aliceHeaders()));
        self::assertSame(0, $live['total']);

        // In the bin, with the date rather than a bare flag.
        $binned = $this->decode(
            $this->request('GET', '/api/v1/projects?deleted=true', $this->aliceHeaders()),
        );
        self::assertSame(1, $binned['total']);

        // Narrowed rather than indexed through `mixed`: a decoded body is
        // `mixed` all the way down, and asserting the shape is also what makes
        // the failure readable when the shape changes.
        $rows = $binned['projects'];
        self::assertIsArray($rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame($projectId, $first['id']);
        self::assertIsString($first['deleted_at']);

        // And gone from every read that is not the bin: a deleted project
        // answers 404 exactly as one that never existed does.
        $shown = $this->request('GET', '/api/v1/projects/' . $projectId, $this->aliceHeaders());
        self::assertSame(404, $shown->getStatusCode());

        $restored = $this->request(
            'POST',
            '/api/v1/projects/' . $projectId . '/undelete',
            $this->aliceHeaders(),
        );
        self::assertSame(200, $restored->getStatusCode());

        $project = $this->decode($restored);
        self::assertNull($project['deleted_at']);
        self::assertSame(['walls' => ['north']], $project['document']);

        // The snapshot taken before deleting is still there. This is the
        // assertion R13 exists for: the cascade used to take it.
        $versions = $this->decode(
            $this->request('GET', '/api/v1/projects/' . $projectId . '/versions', $this->aliceHeaders()),
        );
        $saved = $versions['versions'];
        self::assertIsArray($saved);
        self::assertCount(1, $saved);
        $snapshot = $saved[0];
        self::assertIsArray($snapshot);
        self::assertSame('before deleting', $snapshot['label']);
    }

    /**
     * Undeleting something that is not deleted is not a thing.
     *
     * 404 rather than 409, and deliberately: a 409 would confirm to somebody
     * guessing at ids that this one is real.
     */
    public function testUndeletingALiveProjectIsNotFound(): void
    {
        $projectId = $this->createdProject();

        $response = $this->request(
            'POST',
            '/api/v1/projects/' . $projectId . '/undelete',
            $this->aliceHeaders(),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * A mistyped filter answers the question it looks like.
     *
     * `?deleted=1`, `?deleted=yes`, `?deleted=` — none of them is `true`, and a
     * client that sent one is asking for the live list whatever it meant. The
     * alternative is a query string that silently returns somebody's bin.
     */
    public function testOnlyTheExactValueTrueAsksForTheBin(): void
    {
        $projectId = $this->createdProject();
        $this->request('DELETE', '/api/v1/projects/' . $projectId, $this->aliceHeaders());

        foreach (['1', 'yes', '', 'TRUE'] as $value) {
            $page = $this->decode(
                $this->request('GET', '/api/v1/projects?deleted=' . $value, $this->aliceHeaders()),
            );

            self::assertSame(0, $page['total'], sprintf('?deleted=%s should ask for live projects', $value));
        }
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body, string $token = 'alice-token'): ResponseInterface
    {
        return $this->request('POST', '/api/v1/projects', $this->headersFor($token), $this->json($body));
    }

    /**
     * A created project, with a document and description the update tests
     * assert against.
     */
    private function createdProject(): string
    {
        $created = $this->decode($this->create([
            'name' => 'Garden',
            'description' => 'Back garden',
            'schema_version' => 1,
            'document' => ['walls' => ['north']],
        ]));

        $id = $created['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $projectId, array $body): ResponseInterface
    {
        return $this->request(
            'PATCH',
            '/api/v1/projects/' . $projectId,
            $this->aliceHeaders(),
            $this->json($body),
        );
    }

    private function snapshot(string $projectId, string $label): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/projects/' . $projectId . '/versions',
            $this->aliceHeaders(),
            $this->json(['label' => $label]),
        );
    }

    /**
     * @param list<int> $versions
     */
    private static function registryAccepting(array $versions): InMemoryProductRegistry
    {
        return new InMemoryProductRegistry(
            configuration: [self::ATLAS => ['project_schema_versions' => ['supported' => $versions]]],
        );
    }

    /**
     * @return array<string, string>
     */
    private function aliceHeaders(): array
    {
        return $this->headersFor('alice-token');
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
    }
}
