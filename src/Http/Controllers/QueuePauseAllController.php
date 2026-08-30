<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Queues\Actions\PauseAllQueues;
use DevactionLabs\HorizonNewDawn\Queues\Actions\ResumeAllQueues;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class QueuePauseAllController
{
    public function store(PauseAllQueues $pause): RedirectResponse
    {
        try {
            $pause->handle();

            return back()->with('toast.success', 'Paused all queues on every connection.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'Could not pause all queues.');
        }
    }

    public function destroy(ResumeAllQueues $resume): RedirectResponse
    {
        try {
            $resume->handle();

            return back()->with(
                'toast.success',
                'Cleared the global queue pause. Individually paused queues remain paused.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'Could not clear the global queue pause.');
        }
    }
}
