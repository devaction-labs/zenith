<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

enum BatchSortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
