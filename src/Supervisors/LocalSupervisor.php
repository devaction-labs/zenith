<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Supervisors;

use DevactionLabs\HorizonNewDawn\Instances\LocalInstanceName;
use Illuminate\Support\Str;

final class LocalSupervisor
{
    public static function matches(object $record, string $supervisor): bool
    {
        if (($record->name ?? null) !== $supervisor || ! str_contains($supervisor, ':')) {
            return false;
        }

        $masterFromName = Str::beforeLast($supervisor, ':');
        $recordMaster = $record->master ?? null;

        if ($recordMaster !== null && (! is_string($recordMaster) || $recordMaster !== $masterFromName)) {
            return false;
        }

        return LocalInstanceName::matches($recordMaster ?? $masterFromName);
    }
}
