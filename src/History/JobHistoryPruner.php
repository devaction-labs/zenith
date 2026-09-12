<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

use Illuminate\Database\Eloquent\Builder;

final readonly class JobHistoryPruner
{
    /**
     * @param  list<array<array-key, mixed>>  $rules
     */
    public function prune(array $rules): JobHistoryPruneOutcome
    {
        $applied = 0;
        $deleted = 0;
        $priorRules = [];

        foreach ($rules as $rawRule) {
            $rule = JobHistoryRetentionRule::tryFromArray($rawRule);

            if ($rule === null) {
                continue;
            }

            $cutoff = $rule->cutoff();

            $query = JobHistory::query();
            $rule->scopeTo($query);

            foreach ($priorRules as $priorRule) {
                $priorRule->excludeFrom($query);
            }

            $query->where(function (Builder $terminal) use ($cutoff): void {
                $terminal->where('completed_at', '<', $cutoff)
                    ->orWhere('failed_at', '<', $cutoff);
            });

            $deleted += $query->toBase()->delete();
            $applied++;

            if ($rule->isWildcard()) {
                break;
            }

            $priorRules[] = $rule;
        }

        return new JobHistoryPruneOutcome(
            rulesApplied: $applied,
            rulesSkipped: count($rules) - $applied,
            rowsDeleted: $deleted,
        );
    }
}
