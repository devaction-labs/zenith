<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Schedule\Actions\RunScheduledEvent;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

final class ScheduleRunController
{
    public function store(string $event, RunScheduledEvent $run): RedirectResponse
    {
        try {
            $description = $run->handle($event);

            return back()->with('toast.success', "Ran {$description}.");
        } catch (RuntimeException) {
            abort(404);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'Could not run the scheduled event.');
        }
    }
}
