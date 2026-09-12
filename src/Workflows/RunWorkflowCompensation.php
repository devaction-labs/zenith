<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Workflows\Concerns\AdoptsStepQueueOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RunWorkflowCompensation implements ShouldQueue
{
    use AdoptsStepQueueOptions, Queueable;

    /**
     * @param  string  $token  The claim token stored on the step when this compensation was queued.
     */
    public function __construct(
        public string $workflowId,
        public string $stepName,
        public string $token,
    ) {}

    /**
     * Create the job for a compensation, adopting the queue attributes it declares.
     */
    public static function for(string $workflowId, string $stepName, string $token, string $compensateJob): self
    {
        return (new self($workflowId, $stepName, $token))->adoptQueueOptions($compensateJob);
    }

    /**
     * @throws Throwable when the compensation failed and the queue can still retry it
     */
    public function handle(AdvanceWorkflow $advance): void
    {
        try {
            $advance->runCompensation($this->workflowId, $this->stepName, $this->token);
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
        app(AdvanceWorkflow::class)->failCompensation($this->workflowId, $this->stepName, $this->token, $exception);
    }
}
