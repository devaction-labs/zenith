<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches;

enum BatchSortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
