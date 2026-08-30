<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

final readonly class DatabaseBatchClearCandidate
{
    /** @param list<string>|null $failedJobIds */
    public function __construct(
        public string $id,
        public BatchClearScope $scope,
        public ?array $failedJobIds,
    ) {}
}
