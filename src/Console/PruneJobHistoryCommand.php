<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\History\JobHistoryPruner;
use DevactionLabs\Zenith\History\JobHistoryRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PruneJobHistoryCommand extends Command
{
    protected $signature = 'zenith:prune-history';

    protected $description = 'Delete durable job history rows older than their matching retention rule';

    public function handle(JobHistoryPruner $pruner): int
    {
        if (! Schema::hasTable(JobHistoryRecorder::TABLE)) {
            $this->components->warn('The zenith_job_history table does not exist. Run the package migrations first.');

            return self::SUCCESS;
        }

        $outcome = $pruner->prune($this->configuredRules());

        if ($outcome->rulesSkipped > 0) {
            $this->components->warn("Skipped {$outcome->rulesSkipped} retention rule(s) that were invalid or unreachable.");
        }

        $this->components->info("Pruned {$outcome->rowsDeleted} job history row(s) using {$outcome->rulesApplied} retention rule(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function configuredRules(): array
    {
        $configuredRules = config('zenith.history.retention', []);
        $rules = [];

        if (is_array($configuredRules)) {
            foreach ($configuredRules as $configuredRule) {
                if (is_array($configuredRule)) {
                    $rules[] = $configuredRule;
                }
            }
        }

        return $rules;
    }
}
