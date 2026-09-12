<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Signals\ReleaseWhileWaiting;
use DevactionLabs\Zenith\Signals\SignalWaiting;
use DevactionLabs\Zenith\Workflows\Concerns\AdoptsStepQueueOptions;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RunWorkflowStep implements Interruptible, ShouldQueue
{
    use AdoptsStepQueueOptions, Queueable;

    /**
     * Seconds to wait before a step that reported waiting for a signal runs again.
     */
    public const int SIGNAL_RETRY_SECONDS = 5;

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
        return (new self($workflowId, $stepName, $token))->adoptQueueOptions($jobClass);
    }

    /**
     * @return list<ReleaseWhileWaiting>
     */
    public function middleware(): array
    {
        return [new ReleaseWhileWaiting];
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
     * Checkpoint a graceful worker shutdown (SIGTERM during a deploy, typically) by
     * recording that this claimed step was interrupted, without releasing or otherwise
     * touching it while it may still be running. WorkflowLifeline's scheduled repair pass
     * picks it up from there on its very next run instead of waiting out its full timeout.
     */
    public function interrupted(int $signal): void
    {
        app(AdvanceWorkflow::class)->markInterrupted($this->workflowId, $this->stepName, $this->token);
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
}
