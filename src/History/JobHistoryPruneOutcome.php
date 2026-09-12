<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

final readonly class JobHistoryPruneOutcome
{
    public function __construct(
        public int $rulesApplied,
        public int $rulesSkipped,
        public int $rowsDeleted,
    ) {}
}
