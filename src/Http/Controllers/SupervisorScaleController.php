<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Http\Requests\ScaleSupervisorRequest;
use DevactionLabs\Zenith\Supervisors\Actions\ScaleSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SupervisorScaleController
{
    public function store(
        ScaleSupervisorRequest $request,
        string $supervisor,
        ScaleSupervisor $scale,
    ): RedirectResponse|JsonResponse {
        try {
            $scale->handle($supervisor, $request->processes());

            $message = 'Supervisor scale requested.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_ACCEPTED);
            }

            return back()->with('toast.success', $message);
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(
                    ['message' => $exception->getMessage()],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            return back()->with('toast.error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $message = 'Supervisor could not be scaled.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return back()->with('toast.error', $message);
        }
    }
}
