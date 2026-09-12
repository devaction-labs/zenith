<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use RuntimeException;
use Throwable;

final class WorkflowAlreadyRunning extends RuntimeException
{
    public static function named(?string $name, ?Throwable $previous = null): self
    {
        return new self(sprintf('A unique workflow [%s] is already running.', $name ?? ''), previous: $previous);
    }
}
