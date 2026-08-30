<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations;

use RuntimeException;

final class BulkOperationMissingStateException extends RuntimeException
{
    public function __construct(string $operationId)
    {
        parent::__construct(
            "Bulk operation snapshot state is missing or expired for operation [{$operationId}].",
        );
    }
}
