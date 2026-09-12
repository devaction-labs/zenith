<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use Closure;
use DevactionLabs\Zenith\Supervisors\Actions\ContinueSupervisor;
use DevactionLabs\Zenith\Supervisors\Actions\PauseSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SupervisorPauseController
{
    public function store(
        Request $request,
        string $supervisor,
        PauseSupervisor $pause,
    ): RedirectResponse|JsonResponse {
        return $this->run(
            request: $request,
            command: function () use ($pause, $supervisor): void {
                $pause->handle($supervisor);
            },
            success: 'Supervisor pause requested.',
            failure: 'Supervisor could not be paused.',
        );
    }

    public function destroy(
        Request $request,
        string $supervisor,
        ContinueSupervisor $continue,
    ): RedirectResponse|JsonResponse {
        return $this->run(
            request: $request,
            command: function () use ($continue, $supervisor): void {
                $continue->handle($supervisor);
            },
            success: 'Supervisor continue requested.',
            failure: 'Supervisor could not be continued.',
        );
    }

    /** @param Closure(): void $command */
    private function run(
        Request $request,
        Closure $command,
        string $success,
        string $failure,
    ): RedirectResponse|JsonResponse {
        try {
            $command();

            if ($request->expectsJson()) {
                return response()->json(['message' => $success], Response::HTTP_ACCEPTED);
            }

            return back()->with('toast.success', $success);
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => $failure], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return back()->with('toast.error', $failure);
        }
    }
}
