<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Actions;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

final readonly class PauseSchedule
{
    public function __construct(
        private Kernel $console,
    ) {}

    /**
     * Pause the scheduler through Laravel's own `schedule:pause` command, so
     * `schedule:run` skips every event until it is resumed.
     *
     * @throws RuntimeException
     */
    public function handle(): void
    {
        if ($this->console->call('schedule:pause') !== 0) {
            throw new RuntimeException('Scheduler pausing is disabled for this application.');
        }
    }
}
