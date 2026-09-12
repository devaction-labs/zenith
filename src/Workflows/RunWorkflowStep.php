<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $workflowId,
        public string $stepName,
    ) {}

    public function handle(AdvanceWorkflow $advance): void
    {
        $advance->run($this->workflowId, $this->stepName);
    }
}
