<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows\Concerns;

use DateTimeInterface;
use DevactionLabs\Zenith\Workflows\StepQueueOptions;
use Illuminate\Queue\Jobs\SyncJob;

trait AdoptsStepQueueOptions
{
    public ?int $tries = null;

    /**
     * @var array<int>|int|null
     */
    public array|int|null $backoff = null;

    public ?int $timeout = null;

    public bool $failOnTimeout = false;

    public ?int $maxExceptions = null;

    public ?string $jobClass = null;

    /**
     * The deadline declared by the class this job runs, which lets a job that waits be
     * released repeatedly without exhausting its attempts.
     */
    public function retryUntil(): ?DateTimeInterface
    {
        if ($this->jobClass === null) {
            return null;
        }

        $instance = app($this->jobClass);

        if (! is_object($instance) || ! method_exists($instance, 'retryUntil')) {
            return null;
        }

        $deadline = $instance->retryUntil();

        return $deadline instanceof DateTimeInterface ? $deadline : null;
    }

    /**
     * Adopt the Laravel queue attributes declared on the class this job runs.
     */
    private function adoptQueueOptions(string $jobClass): static
    {
        $options = StepQueueOptions::of($jobClass);

        $this->jobClass = $jobClass;
        $this->tries = $options->tries;
        $this->backoff = $options->backoff;
        $this->timeout = $options->timeout;
        $this->failOnTimeout = $options->failOnTimeout;
        $this->maxExceptions = $options->maxExceptions;

        return $this->onConnection($options->connection)->onQueue($options->queue);
    }

    /**
     * The sync driver runs a job inline and cannot redeliver it, so a failed attempt is final there.
     */
    private function redeliverable(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }
}
