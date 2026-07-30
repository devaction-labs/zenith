<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

use RuntimeException;

final class RetainedJobIndexWarming extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The retained job index is warming.');
    }
}
