<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Job\Domain\JobRepository;
use App\Job\Domain\Retention;

/**
 * Deletes what the queue has finished with (ADR-067, {@see Retention}).
 *
 * Nothing pruned `jobs` or `job_runs`, which ADR-067 turned from a slow leak
 * into a steady one: the platform now enqueues nine rows a day by itself, and
 * `job_runs` takes one per cron pass — 1 440 a day, half a million a year, to
 * carry a question only ever asked about the last few minutes.
 *
 * It is the one handler that deletes rows, so two things are deliberate about
 * what it will not do. **Nothing unfinished** is touched, including an unfinished
 * *run*, which is the only record that a pass died mid-flight. And the pass is
 * **bounded**: a deployment with a large backlog converges over several passes
 * rather than holding locks for the length of one enormous statement, and the
 * counts come back so that convergence is visible rather than assumed.
 *
 * The windows are constants and not configuration, which {@see Retention}
 * explains: the act here is an irreversible delete, and changing a constant is a
 * commit somebody reviews.
 */
final class PruneJobs implements JobHandler
{
    public const TYPE = 'sweep.jobs';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly Retention $retention,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Job $job): array
    {
        $deleted = $this->jobs->prune(
            $this->retention->succeededDays,
            $this->retention->failedDays,
            $this->retention->runDays,
            $this->retention->batch,
        );

        return [
            'jobs' => $deleted['jobs'],
            'runs' => $deleted['runs'],
            // Whether a pass filled its allowance, which is how somebody tells
            // "nothing to do" from "still catching up" without reading the
            // table.
            'more' => $deleted['jobs'] >= $this->retention->batch
                || $deleted['runs'] >= $this->retention->batch,
        ];
    }
}
