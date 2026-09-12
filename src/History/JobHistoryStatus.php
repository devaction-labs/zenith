<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

enum JobHistoryStatus: string
{
    case Completed = 'completed';
    case Silenced = 'silenced';
    case Failed = 'failed';
}
