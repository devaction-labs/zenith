<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Jobs\Actions\CancelPendingJob;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationResult;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class PendingJobController
{
    public function destroy(CancelPendingJob $cancel, string $job): RedirectResponse
    {
        try {
            return match ($cancel->handle($job)) {
                PendingJobCancellationResult::Cancelled => to_route(
                    'zenith.jobs.index',
                    ['type' => 'pending'],
                )->with('toast.success', 'Job cancelled.'),
                PendingJobCancellationResult::Batched => back()->with(
                    'toast.error',
                    'This job belongs to a batch. Cancel the batch instead.',
                ),
                PendingJobCancellationResult::NotCancellable => back()->with(
                    'toast.error',
                    'This job can no longer be cancelled.',
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'The job could not be cancelled.');
        }
    }
}
