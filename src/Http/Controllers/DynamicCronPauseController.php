<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Schedule\DynamicCron;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use Illuminate\Http\RedirectResponse;

final class DynamicCronPauseController
{
    public function store(DynamicSchedule $crons, int $cron): RedirectResponse
    {
        $name = DynamicCron::query()->findOrFail($cron)->name;

        $crons->pause($cron);

        return back()->with('toast.success', "Paused the {$name} cron.");
    }

    public function destroy(DynamicSchedule $crons, int $cron): RedirectResponse
    {
        $name = DynamicCron::query()->findOrFail($cron)->name;

        $crons->resume($cron);

        return back()->with('toast.success', "Resumed the {$name} cron.");
    }
}
