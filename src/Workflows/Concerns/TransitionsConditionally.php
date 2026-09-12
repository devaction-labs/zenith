<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows\Concerns;

use DevactionLabs\Zenith\Workflows\WorkflowStatus;

trait TransitionsConditionally
{
    /**
     * Persist the attributes only while the stored status is still one of the given statuses,
     * so concurrent workers can race for the same row and exactly one of them wins.
     *
     * @param  list<WorkflowStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transition(array $from, array $attributes): bool
    {
        $this->forceFill($attributes);

        $updated = $this->newQuery()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(static fn (WorkflowStatus $status): string => $status->value, $from))
            ->update($this->getDirty());

        if ($updated === 1) {
            $this->syncOriginal();

            return true;
        }

        $this->refresh();

        return false;
    }
}
