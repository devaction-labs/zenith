<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Batches\DatabaseBatchMetadataSynchronizer;
use Illuminate\Console\Command;

final class WarmBatchMetadataCommand extends Command
{
    protected $signature = 'zenith:warm-batch-metadata';

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
