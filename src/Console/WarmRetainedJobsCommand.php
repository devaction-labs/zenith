<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Jobs\RetainedJobIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobType;
use Illuminate\Console\Command;

final class WarmRetainedJobsCommand extends Command
{
    protected $signature = 'zenith:warm-retained-jobs';

    protected $description = 'Warm the retained job Redis indexes for every Horizon retained job type';

    public function handle(RetainedJobIndex $index): int
    {
        foreach (RetainedJobType::cases() as $type) {
            $this->components->task(
                "Warming retained {$type->value} jobs",
                function () use ($index, $type): void {
                    $index->synchronize($type, force: true);
                },
            );
        }

        $this->components->info('Retained job indexes are warm.');

        return self::SUCCESS;
    }
}
