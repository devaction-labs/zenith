<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Console;

use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchMetadataSynchronizer;
use Illuminate\Console\Command;

final class WarmBatchMetadataCommand extends Command
{
    protected $signature = 'horizon-new-dawn:warm-batch-metadata';

    protected $description = 'Warm immutable queue and connection metadata for retained batches';

    public function handle(
        DatabaseBatchCapability $capability,
        DatabaseBatchMetadataSynchronizer $synchronizer,
    ): int {
        if (! $capability->attributionSupported()) {
            $this->components->error(
                $capability->attributionMessage()
                    ?? 'Batch queue and connection attribution are unavailable.',
            );

            return self::FAILURE;
        }

        $inserted = $synchronizer->sync();

        $this->components->info(
            "Batch destination metadata is warm ({$inserted} newly captured).",
        );

        return self::SUCCESS;
    }
}
