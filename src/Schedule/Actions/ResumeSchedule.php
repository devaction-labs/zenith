<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Actions;

use Illuminate\Contracts\Console\Kernel;

final readonly class ResumeSchedule
{
    public function __construct(
        private Kernel $console,
    ) {}

    /**
     * Resume the scheduler through Laravel's own `schedule:resume` command.
     */
    public function handle(): void
    {
        $this->console->call('schedule:resume');
    }
}
