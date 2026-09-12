<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  The claim token stored on the step when this job was dispatched.
     */
    public function __construct(
        public string $workflowId,
        public string $stepName,
        public string $token,
    ) {}

    public function handle(AdvanceWorkflow $advance): void
    {
        $advance->run($this->workflowId, $this->stepName, $this->token);
    }
}
