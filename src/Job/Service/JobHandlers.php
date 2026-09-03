<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\JobHandler;
use App\Shared\Exceptions\ConflictException;

/**
 * Which handler runs which type.
 *
 * The same shape as PaymentProviders and EInvoiceProviders: a registry built
 * once in the container, so adding a job type is adding a class and a line of
 * wiring rather than editing a match arm in the runner.
 */
final class JobHandlers
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /**
     * @param list<JobHandler> $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function for(string $type): JobHandler
    {
        return $this->handlers[$type]
            // Refused at enqueue time, so a job nothing can run never reaches
            // the queue. A type with no handler would otherwise be claimed,
            // fail, back off and fail again until its attempts ran out.
            ?? throw new ConflictException(
                'JOB_TYPE_UNKNOWN',
                'No handler is registered for that kind of job.',
                ['type' => $type],
            );
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
