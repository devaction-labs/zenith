<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Signals\SignalWaiting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

final class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before a step that is waiting for a signal runs again.
     */
    public const int SIGNAL_RETRY_SECONDS = 5;

    public ?int $tries = null;

    /**
     * @var array<int>|int|null
     */
    public array|int|null $backoff = null;

    public ?int $timeout = null;

    public bool $failOnTimeout = false;

    public ?int $maxExceptions = null;

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

        $job->tries = $options->tries;
        $job->backoff = $options->backoff;
        $job->timeout = $options->timeout;
        $job->failOnTimeout = $options->failOnTimeout;
        $job->maxExceptions = $options->maxExceptions;

        return $job->onConnection($options->connection)->onQueue($options->queue);
    }

    /**
     * @throws Throwable when the step failed and the queue can still retry it
     */
    public function handle(AdvanceWorkflow $advance): void
    {
        try {
            $advance->run($this->workflowId, $this->stepName, $this->token);
        } catch (SignalWaiting) {
            if ($this->redeliverable()) {
                $advance->redeliver($this->workflowId, $this->stepName, $this->token, self::SIGNAL_RETRY_SECONDS);
            }
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
     * The sync driver runs a job inline and cannot redeliver it, so a failed attempt is final there.
     */
    private function redeliverable(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }
}
