<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

use Illuminate\Queue\Events\JobFailed;

/**
 * Advance a chain once Laravel reports one of its jobs as permanently
 * failed, the terminal-failure half of the "success or terminal failure"
 * rule EnforceChainOrder cannot decide on its own from inside a single
 * attempt.
 */
final class ChainFailureListener
{
    public function handle(JobFailed $event): void
    {
        $chain = ChainPayload::from($event->job->payload());

        if ($chain !== null) {
            ChainSequencer::advance($chain->key, $chain->ticket);
        }
    }
}
