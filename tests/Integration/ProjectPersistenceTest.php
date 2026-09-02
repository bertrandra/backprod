<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Project\Domain\Project;
use App\Project\Domain\ProjectChanges;
use App\Project\Domain\ProjectDraft;
use App\Project\Domain\ProjectVersion;
use App\Project\Infrastructure\PostgresProjectRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

/**
 * Projects against a real database.
 *
 * The milestone's exit criterion lives here: a snapshot, then a restore, and
 * the document that comes back is byte-for-byte the one that went in. That
 * cannot be shown against a double — JSONB is what stores it, and JSONB is
 * what normalises it.
 */
#[CoversNothing]
final class ProjectPersistenceTest extends DatabaseTestCase
{
    private string $tenantId = '';
    private string $otherTenantId = '';
    private string $productId = '';
    private string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->productId = $this->seedProduct('atlas');
        $this->tenantId = $this->seedTenant('acme');
        $this->otherTenantId = $this->seedTenant('globex');
        $this->userId = $this->seedUser('sub-alice');
    }

    /**
     * The exit criterion for M4.
     *
     * "Byte-identical" is a claim about the round trip, not about the request:
     * JSONB normalises on the first write — it sorts object keys and drops
     * insignificant whitespace — and what is stored afterwards is stable.
     * So the comparison is between the stored document before the snapshot
     * and the stored document after the restore, which is what a client
     * actually gets back both times.
     */
    public function testASnapshotAndRestoreRoundTripsTheDocumentExactly(): void
    {
        $projects = $this->repository();

        $project = $projects->create($this->draft('{"walls":["north"],"scale":100,"meta":{"units":"m"}}'));
        $before = self::encoded($project);

        $version = $projects->snapshot($project, 'original', $this->userId);

        // Overwrite it thoroughly: different keys, different types, different
        // nesting, so a restore that half-worked would show.
        $projects->update($project, ProjectChanges::of(
            'Changed',
            true,
            'A different description',
            null,
            self::decode('{"walls":[],"scale":1,"extra":{"deep":{"deeper":[1,2,3]}}}'),
        ));

        $restored = $projects->restore($project, $version, $this->userId);

        self::assertSame($before, self::encoded($restored));
        self::assertSame($project->name, $restored->name);
        self::assertSame($project->description, $restored->description);
        self::assertSame($project->schemaVersion, $restored->schemaVersion);
    }

    /**
     * The normalisation the criterion above is careful about, stated
     * outright rather than left as folklore: keys come back sorted, and
     * writing the same document twice gives the same bytes both times.
     */
    public function testJsonbNormalisesOnceAndIsStableAfterwards(): void
    {
        $projects = $this->repository();

        $project = $projects->create($this->draft('{"zebra": 1, "alpha": 2}'));

        $stored = self::encoded($project);
        self::assertSame('{"alpha":2,"zebra":1}', $stored, 'JSONB stores object keys sorted');

        // Stable from then on: a snapshot of the stored document and a
        // restore of it produce the same bytes, which is the property the
        // round-trip criterion actually depends on.
        $version = $projects->snapshot($project, null, $this->userId);
        self::assertSame($stored, json_encode($version->document));

        $restored = $projects->restore($project, $version, $this->userId);
        self::assertSame($stored, self::encoded($restored));
    }

    /**
     * Array element order is content, not formatting: JSONB preserves it.
     * If it did not, no snapshot of a project with an ordered layer stack
     * would be worth taking.
     */
    public function testArrayOrderIsPreserved(): void
    {
        $project = $this->repository()->create($this->draft('{"layers":["roof","walls","floor"]}'));

        self::assertSame('{"layers":["roof","walls","floor"]}', self::encoded($project));
    }

    /**
     * The failure the object-tree decoding exists to prevent, verified where
     * it would actually happen. Decoding into a PHP array turns {"0": …}
     * into a list, and PostgreSQL would never complain.
     */
    public function testAnObjectKeyedByNumbersComesBackAsAnObject(): void
    {
        $project = $this->repository()->create(
            $this->draft('{"layers":{"0":{"kind":"wall"},"1":{"kind":"roof"}}}'),
        );

        self::assertSame(
            '{"layers":{"0":{"kind":"wall"},"1":{"kind":"roof"}}}',
            self::encoded($project),
        );
    }

    public function testVersionsAreNumberedFromOne(): void
    {
        $projects = $this->repository();
        $project = $projects->create($this->draft('{"walls":[]}'));

        $first = $projects->snapshot($project, 'one', $this->userId);
        $second = $projects->snapshot($project, 'two', $this->userId);

        self::assertSame(1, $first->versionNumber);
        self::assertSame(2, $second->versionNumber);

        // Newest first, which is the order a history is read in.
        self::assertSame([2, 1], array_map(
            static fn (ProjectVersion $v): int => $v->versionNumber,
            $projects->listVersions($project),
        ));
    }

    /**
     * A snapshot records what is stored, not what the caller was holding.
     * The Project handed in may be a request out of date.
     */
    public function testASnapshotCapturesTheStoredStateNotTheCallersCopy(): void
    {
        $projects = $this->repository();
        $stale = $projects->create($this->draft('{"walls":["north"]}'));

        $projects->update($stale, ProjectChanges::of(null, false, null, null, self::decode('{"walls":["south"]}')));

        $version = $projects->snapshot($stale, null, $this->userId);

        self::assertSame('{"walls":["south"]}', json_encode($version->document));
    }

    /**
     * Restoring is the one operation that overwrites a project, so it is the
     * one that must capture what it overwrites — in the same transaction,
     * or a crash between the two would lose exactly the work the feature
     * exists to protect.
     */
    public function testRestoringRecordsTheStateItReplaced(): void
    {
        $projects = $this->repository();
        $project = $projects->create($this->draft('{"walls":["north"]}'));
        $original = $projects->snapshot($project, 'original', $this->userId);

        $projects->update($project, ProjectChanges::of(null, false, null, null, self::decode('{"walls":["east"]}')));
        $projects->restore($project, $original, $this->userId);

        $versions = $projects->listVersions($project);

        self::assertCount(2, $versions);
        self::assertSame('{"walls":["east"]}', json_encode($versions[0]->document));
        self::assertSame('Before restoring version 1', $versions[0]->label);
    }

    public function testDeletingAProjectTakesItsVersionsWithIt(): void
    {
        $projects = $this->repository();
        $project = $projects->create($this->draft('{"walls":[]}'));
        $projects->snapshot($project, null, $this->userId);

        $projects->delete($project);

        self::assertNull($projects->find($this->tenantId, $this->productId, $project->id));
        self::assertSame(0, $this->countVersionsOf($project->id));
    }

    public function testAProjectIsInvisibleFromAnotherTenant(): void
    {
        $projects = $this->repository();
        $project = $projects->create($this->draft('{"walls":[]}'));

        self::assertNotNull($projects->find($this->tenantId, $this->productId, $project->id));
        self::assertNull($projects->find($this->otherTenantId, $this->productId, $project->id));
    }

    /**
     * PostgreSQL raises on `= 'not-a-uuid'` against a UUID column. Without
     * the format check that would be a 500 for a typo in a URL, which is
     * both wrong and an oracle for what an id looks like.
     */
    public function testAMalformedIdIsNotFoundRatherThanADatabaseError(): void
    {
        self::assertNull($this->repository()->find($this->tenantId, $this->productId, 'not-a-uuid'));
    }

    public function testDuplicatingCopiesTheStoredDocumentAndNoHistory(): void
    {
        $projects = $this->repository();
        $project = $projects->create($this->draft('{"walls":["north"],"scale":100}'));
        $projects->snapshot($project, 'original', $this->userId);

        $copy = $projects->duplicate($project, 'Garden copy', $this->userId);

        self::assertNotSame($project->id, $copy->id);
        self::assertSame(self::encoded($project), self::encoded($copy));
        self::assertSame($this->tenantId, $copy->tenantId);
        self::assertSame([], $projects->listVersions($copy));
    }

    public function testListingIsScopedPagedAndMostRecentFirst(): void
    {
        $projects = $this->repository();

        $first = $projects->create($this->draft('{"n":1}', 'First'));
        $second = $projects->create($this->draft('{"n":2}', 'Second'));

        // Touching the older one moves it to the front, which is what an
        // "updated_at DESC" listing is for.
        $projects->update($first, ProjectChanges::of('First again', false, null, null, null));

        $listed = $projects->listForTenant($this->tenantId, $this->productId, 10, 0);

        self::assertSame(
            [$first->id, $second->id],
            array_map(static fn (Project $p): string => $p->id, $listed),
        );

        self::assertSame(2, $projects->countForTenant($this->tenantId, $this->productId));
        self::assertCount(1, $projects->listForTenant($this->tenantId, $this->productId, 1, 0));
        self::assertSame(0, $projects->countForTenant($this->otherTenantId, $this->productId));
    }

    /**
     * The column is INTEGER, and the searchable columns exist so that
     * filtering never has to reach inside the document.
     */
    public function testSchemaVersionIsStoredAsAColumn(): void
    {
        $projects = $this->repository();
        $projects->create($this->draft('{"walls":[]}'));

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM projects WHERE schema_version = 1 AND tenant_id = :tenant',
            ['tenant' => $this->tenantId],
        );

        self::assertSame(1, is_numeric($count) ? (int) $count : 0);
    }

    // --- Helpers ------------------------------------------------------------

    private function repository(): PostgresProjectRepository
    {
        return new PostgresProjectRepository($this->connection);
    }

    private function draft(string $documentJson, string $name = 'Garden'): ProjectDraft
    {
        return new ProjectDraft(
            $this->tenantId,
            $this->productId,
            $name,
            'Back garden',
            1,
            self::decode($documentJson),
            $this->userId,
        );
    }

    private static function encoded(Project $project): string
    {
        $encoded = json_encode($project->document);

        if ($encoded === false) {
            throw new RuntimeException('A stored document did not encode.');
        }

        return $encoded;
    }

    private static function decode(string $json): object
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (!is_object($decoded)) {
            throw new RuntimeException('The fixture is not a JSON object.');
        }

        return $decoded;
    }

    private function countVersionsOf(string $projectId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM project_versions WHERE project_id = :id',
            ['id' => $projectId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function seedProduct(string $code): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
        );

        self::assertIsString($id);

        return $id;
    }

    private function seedTenant(string $slug): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => ucfirst($slug), 'slug' => $slug],
        );

        self::assertIsString($id);

        return $id;
    }

    private function seedUser(string $subject): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO users (auth_subject) VALUES (:subject) RETURNING id',
            ['subject' => $subject],
        );

        self::assertIsString($id);

        return $id;
    }
}
