<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DateTimeInterface;
use DevactionLabs\Zenith\Signals\SignalWaiting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

final class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before a step that reported waiting for a signal runs again.
     */
    public const int SIGNAL_RETRY_SECONDS = 5;

    /**
     * The signals middleware that binds the queue job so Signal::await can release it
     * while a step waits. It is only present once the signals module ships it.
     */
    private const string RELEASE_WHILE_WAITING = 'DevactionLabs\Zenith\Signals\ReleaseWhileWaiting';

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
     * @param  string  $token  The claim token stored on the step when this job was dispatched.
     */
    public function __construct(
        public string $workflowId,
        public string $stepName,
        public string $token,
    ) {}

    /**
     * Create the job for a step, adopting the queue attributes declared on the step class.
     */
    public static function for(string $workflowId, string $stepName, string $token, string $jobClass): self
    {
        $options = StepQueueOptions::of($jobClass);
        $job = new self($workflowId, $stepName, $token);

        $job->jobClass = $jobClass;
        $job->tries = $options->tries;
        $job->backoff = $options->backoff;
        $job->timeout = $options->timeout;
        $job->failOnTimeout = $options->failOnTimeout;
        $job->maxExceptions = $options->maxExceptions;

        return $job->onConnection($options->connection)->onQueue($options->queue);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $middleware = self::RELEASE_WHILE_WAITING;

        if (! class_exists($middleware)) {
            return [];
        }

        $instance = new $middleware;

        return is_object($instance) ? [$instance] : [];
    }

    /**
     * The deadline declared by the step class, which lets a step that waits for a signal
     * be released repeatedly without exhausting its attempts.
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
     * @throws Throwable when the step failed and the queue can still retry it
     */
    public function handle(AdvanceWorkflow $advance): void
    {
        try {
            $advance->run($this->workflowId, $this->stepName, $this->token);
        } catch (SignalWaiting) {
            $this->scheduleSignalRetry($advance);
        } catch (Throwable $exception) {
            if ($this->redeliverable()) {
                throw $exception;
            }

            if ($this->job === null) {
                $this->failed($exception);

                return;
            }

            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AdvanceWorkflow::class)->failStep($this->workflowId, $this->stepName, $this->token, $exception);
    }

    /**
     * Waiting for a signal releases this job, which the queue redelivers on its own. Only a
     * step that reported waiting without releasing needs a fresh job, which also keeps the
     * attempts of the current job intact.
     */
    private function scheduleSignalRetry(AdvanceWorkflow $advance): void
    {
        $job = $this->job;

        if ($job instanceof Job && $job->isReleased()) {
            return;
        }

        if ($this->redeliverable()) {
            $advance->redeliver($this->workflowId, $this->stepName, $this->token, self::SIGNAL_RETRY_SECONDS);
        }
    }

    /**
     * The sync driver runs a job inline and cannot redeliver it, so a failed attempt is final there.
     */
    private function redeliverable(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }
}
