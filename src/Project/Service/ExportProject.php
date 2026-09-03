<?php

declare(strict_types=1);

namespace App\Project\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Project\Domain\ProjectRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Storage\Service\Assets;
use DateTimeImmutable;
use RuntimeException;

/**
 * Turns a project into a downloadable file, off the request path.
 *
 * This is where M7's two halves meet, and it is the milestone's exit
 * criterion in one class: a long export returns a job id rather than holding
 * an HTTP worker open, and what it produces lands in the store rather than in
 * PostgreSQL (non-negotiable #9).
 *
 * Idempotent, as {@see JobHandler} requires — but not by doing nothing the
 * second time. A repeat produces another asset with identical bytes, which is
 * harmless: an export is a snapshot, two snapshots of the same thing are the
 * same snapshot, and the checksum says so. Deduplicating would mean deciding
 * that a caller asking twice wanted one answer, which is not this handler's
 * call to make.
 */
final class ExportProject implements JobHandler
{
    public const TYPE = 'export.project';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly Assets $assets,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        $projectId = $job->payload['project_id'] ?? null;

        if (!is_string($projectId) || $job->tenantId === null || $job->productId === null) {
            // A job whose payload cannot name what to export will never
            // succeed, so it fails loudly rather than retrying twice more.
            throw new RuntimeException('This export job does not name a project.');
        }

        $project = $this->projects->find($job->tenantId, $job->productId, $projectId);

        if ($project === null) {
            // Deleted between the request and the run. Not an error worth
            // retrying — it will not come back.
            throw new NotFoundException('Project not found.', [], 'PROJECT_NOT_FOUND');
        }

        $contents = json_encode(
            [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'schema_version' => $project->schemaVersion,
                'document' => $project->document,
                'exported_at' => (new DateTimeImmutable())->format(DATE_RFC3339),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($contents === false) {
            throw new RuntimeException('The project could not be encoded.');
        }

        $asset = $this->assets->store(
            $job->tenantId,
            $job->productId,
            $project->id,
            $contents,
            sprintf('%s.json', $project->id),
            'application/json',
        );

        // The result is what the client polling GET /jobs/{id} reads to find
        // out where its export went.
        return [
            'asset_id' => $asset->id,
            'byte_size' => $asset->byteSize,
            'checksum' => $asset->checksum,
        ];
    }
}
