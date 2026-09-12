<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Http\Requests\DynamicCronRequest;
use DevactionLabs\Zenith\Schedule\DynamicCron;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class DynamicCronController
{
    public function store(DynamicCronRequest $request, DynamicSchedule $crons): RedirectResponse
    {
        $data = $request->getData();

        try {
            $crons->create($data->name, $data->expression, $data->jobClass, $data->payload, $data->timezone);

            return back()->with('toast.success', "Created the {$data->name} cron.");
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['expression' => $exception->getMessage()])->withInput();
        }
    }

    public function update(DynamicCronRequest $request, DynamicSchedule $crons, int $cron): RedirectResponse
    {
        $data = $request->getData();

        try {
            $crons->update($cron, $data->name, $data->expression, $data->jobClass, $data->payload, $data->timezone);

            return back()->with('toast.success', "Updated the {$data->name} cron.");
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['expression' => $exception->getMessage()])->withInput();
        }
    }

    public function destroy(DynamicSchedule $crons, int $cron): RedirectResponse
    {
        $name = DynamicCron::query()->findOrFail($cron)->name;

        $crons->delete($cron);

        return back()->with('toast.success', "Deleted the {$name} cron.");
    }
}
