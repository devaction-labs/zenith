<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Http\Requests\PauseQueueRequest;
use DevactionLabs\HorizonNewDawn\Http\Requests\ResumeQueueRequest;
use DevactionLabs\HorizonNewDawn\Queues\Actions\PauseQueue;
use DevactionLabs\HorizonNewDawn\Queues\Actions\ResumeQueue;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class QueuePauseController
{
    public function __construct(
        private readonly FrameworkCapabilities $capabilities,
    ) {}

    public function store(PauseQueueRequest $request, PauseQueue $pause): RedirectResponse
    {
        abort_unless($this->capabilities->queuePausing, 404);

        $data = $request->getData();

        try {
            $until = $pause->handle($data);

            return back()->with(
                'toast.success',
                "Paused {$data->queue} {$this->durationDescription(
                    $until === null ? null : $data->durationMinutes,
                )}.",
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', "Could not pause {$data->queue}.");
        }
    }

    public function destroy(ResumeQueueRequest $request, ResumeQueue $resume): RedirectResponse
    {
        abort_unless($this->capabilities->queuePausing, 404);

        $data = $request->getData();

        try {
            $resume->handle($data->connection, $data->queue);

            return back()->with('toast.success', "The {$data->queue} queue has been signaled to resume.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', "Could not resume {$data->queue}.");
        }
    }

    private function durationDescription(?int $durationMinutes): string
    {
        if ($durationMinutes === null) {
            return 'indefinitely';
        }

        if ($durationMinutes % 60 === 0) {
            $hours = intdiv($durationMinutes, 60);

            return "for {$hours} ".($hours === 1 ? 'hour' : 'hours');
        }

        return "for {$durationMinutes} ".($durationMinutes === 1 ? 'minute' : 'minutes');
    }
}
