<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations;

final readonly class BulkOperationChunkResult
{
    public function __construct(
        public string $operationId,
        public int $totalAffected,
        public bool $complete,
    ) {}

    public static function continuing(string $operationId, int $totalAffected): self
    {
        return new self($operationId, $totalAffected, complete: false);
    }

    public static function completed(string $operationId, int $totalAffected): self
    {
        return new self($operationId, $totalAffected, complete: true);
    }
}
