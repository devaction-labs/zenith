<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Workflows\WorkflowLifeline;
use Illuminate\Console\Command;

/**
 * Re-dispatches or fails workflow steps stuck in running or dispatched longer than
 * their step class's own timeout. Schedule it periodically (for example
 * ->everyMinute()->withoutOverlapping()) to bound how long a step can stay stuck after
 * a worker dies, times out, or is interrupted by a deploy.
 */
final class RepairWorkflowsCommand extends Command
{
    protected $signature = 'zenith:repair-workflows';

    protected $description = 'Re-dispatch or fail workflow steps stuck running or dispatched past their timeout';

    public function handle(WorkflowLifeline $lifeline): int
    {
        $repaired = $lifeline->repair();

        $this->components->info(
            $repaired === 1 ? 'Repaired 1 stale workflow step.' : "Repaired {$repaired} stale workflow steps.",
        );

        return self::SUCCESS;
    }
}
