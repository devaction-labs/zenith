<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Schedule\Actions\PauseSchedule;
use DevactionLabs\Zenith\Schedule\Actions\ResumeSchedule;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class SchedulePauseController
{
    public function store(PauseSchedule $pause): RedirectResponse
    {
        try {
            $pause->handle();

            return back()->with('toast.success', 'Paused the scheduler.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'Could not pause the scheduler.');
        }
    }

    public function destroy(ResumeSchedule $resume): RedirectResponse
    {
        try {
            $resume->handle();

            return back()->with('toast.success', 'Resumed the scheduler.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'Could not resume the scheduler.');
        }
    }
}
