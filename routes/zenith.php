<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Http\Controllers\AuditController;
use DevactionLabs\Zenith\Http\Controllers\BatchCancelController;
use DevactionLabs\Zenith\Http\Controllers\BatchClearController;
use DevactionLabs\Zenith\Http\Controllers\BatchController;
use DevactionLabs\Zenith\Http\Controllers\BatchFailedJobClearController;
use DevactionLabs\Zenith\Http\Controllers\BatchRetryController;
use DevactionLabs\Zenith\Http\Controllers\DashboardController;
use DevactionLabs\Zenith\Http\Controllers\DelayedJobReleaseController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobClearAllController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobRetryAllController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobRetryController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedClearController;
use DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedRetryController;
use DevactionLabs\Zenith\Http\Controllers\HorizonPauseController;
use DevactionLabs\Zenith\Http\Controllers\HorizonTerminationController;
use DevactionLabs\Zenith\Http\Controllers\JobController;
use DevactionLabs\Zenith\Http\Controllers\JobRetryController;
use DevactionLabs\Zenith\Http\Controllers\MetricController;
use DevactionLabs\Zenith\Http\Controllers\MetricsController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringFailedJobRetryController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringRecentJobController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringTagController;
use DevactionLabs\Zenith\Http\Controllers\PendingJobClearAllController;
use DevactionLabs\Zenith\Http\Controllers\PendingJobController;
use DevactionLabs\Zenith\Http\Controllers\PendingJobsCancellationController;
use DevactionLabs\Zenith\Http\Controllers\PendingJobsSelectedCancelController;
use DevactionLabs\Zenith\Http\Controllers\QueueBatchRetryController;
use DevactionLabs\Zenith\Http\Controllers\QueueClearAllController;
use DevactionLabs\Zenith\Http\Controllers\QueueClearController;
use DevactionLabs\Zenith\Http\Controllers\QueueController;
use DevactionLabs\Zenith\Http\Controllers\QueueFailedJobRetryController;
use DevactionLabs\Zenith\Http\Controllers\QueuePauseAllController;
use DevactionLabs\Zenith\Http\Controllers\QueuePauseController;
use DevactionLabs\Zenith\Http\Controllers\RunningInstanceController;
use DevactionLabs\Zenith\Http\Controllers\ScheduleController;
use DevactionLabs\Zenith\Http\Controllers\ScheduleRunController;
use DevactionLabs\Zenith\Http\Controllers\SupervisorController;
use DevactionLabs\Zenith\Http\Controllers\SupervisorPauseController;
use DevactionLabs\Zenith\Http\Controllers\SupervisorScaleController;
use DevactionLabs\Zenith\Http\Controllers\WorkflowCancelController;
use DevactionLabs\Zenith\Http\Controllers\WorkflowController;
use DevactionLabs\Zenith\Http\Controllers\WorkflowRetryController;
use DevactionLabs\Zenith\Http\Middleware\AuthorizeHorizonAbility;
use DevactionLabs\Zenith\Http\Middleware\EnsureQueuePausingAllIsSupported;
use DevactionLabs\Zenith\Http\Middleware\EnsureQueuePausingIsSupported;
use Illuminate\Support\Facades\Route;

if (! function_exists('horizonAbility')) {
    function horizonAbility(string $ability): string
    {
        return AuthorizeHorizonAbility::class.':'.$ability;
    }
}

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/instances', [RunningInstanceController::class, 'index'])->name('instances.index');
Route::post('/supervisors/{supervisor}/pause', [SupervisorPauseController::class, 'store'])
    ->middleware(horizonAbility('manageInstances'))
    ->where('supervisor', '.+?')
    ->name('supervisors.pause.store');
Route::delete('/supervisors/{supervisor}/pause', [SupervisorPauseController::class, 'destroy'])
    ->middleware(horizonAbility('manageInstances'))
    ->where('supervisor', '.+?')
    ->name('supervisors.pause.destroy');
Route::post('/supervisors/{supervisor}/scale', [SupervisorScaleController::class, 'store'])
    ->middleware(horizonAbility('manageInstances'))
    ->where('supervisor', '.+?')
    ->name('supervisors.scale.store');
Route::get('/supervisors/{supervisor}', [SupervisorController::class, 'show'])
    ->where('supervisor', '.+')
    ->name('supervisors.show');
Route::post('/instances/terminate', [HorizonTerminationController::class, 'store'])
    ->middleware(horizonAbility('manageInstances'))
    ->name('instances.terminate.store');
Route::post('/instances/{instance}/pause', [HorizonPauseController::class, 'store'])
    ->middleware(horizonAbility('manageInstances'))
    ->name('instances.pause.store');
Route::delete('/instances/{instance}/pause', [HorizonPauseController::class, 'destroy'])
    ->middleware(horizonAbility('manageInstances'))
    ->name('instances.pause.destroy');

Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
Route::post('/monitoring', [MonitoringController::class, 'store'])
    ->middleware(horizonAbility('manageMonitoring'))
    ->name('monitoring.store');
Route::delete('/monitoring/actions/clear-jobs/{tag}', [MonitoringRecentJobController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->where('tag', '.+')
    ->name('monitoring.jobs.destroy');
Route::post('/monitoring/actions/retry-failed/{tag}', [MonitoringFailedJobRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->where('tag', '.+')
    ->name('monitoring.retry-failed.store');
Route::delete('/monitoring/actions/stop/{tag}', [MonitoringController::class, 'destroy'])
    ->middleware(horizonAbility('manageMonitoring'))
    ->where('tag', '.+')
    ->name('monitoring.destroy');
Route::get('/monitoring/{tag}/{status?}', [MonitoringTagController::class, 'show'])
    ->where('tag', '.+?')
    ->where('status', 'jobs|failed')
    ->name('monitoring.show');

Route::get('/metrics', [MetricsController::class, 'redirect'])->name('metrics.redirect');
Route::get('/metrics/{type}', [MetricsController::class, 'index'])
    ->where('type', 'jobs|queues')
    ->name('metrics.index');
Route::get('/metrics/{type}/{slug}', [MetricController::class, 'show'])
    ->where('type', 'jobs|queues')
    ->where('slug', '.+')
    ->name('metrics.show');

Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
Route::delete('/batches/{scope}', [BatchClearController::class, 'destroy'])
    ->middleware(horizonAbility('manageBatches'))
    ->where('scope', 'incomplete|complete|finished|cancelled')
    ->name('batches.clear.destroy');
Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
Route::post('/batches/{batch}/cancel', [BatchCancelController::class, 'store'])
    ->middleware(horizonAbility('manageBatches'))
    ->name('batches.cancel.store');
Route::post('/batches/{batch}/retry', [BatchRetryController::class, 'store'])
    ->middleware(horizonAbility('manageBatches'))
    ->name('batches.retry.store');
Route::delete('/batches/{batch}/failed', [BatchFailedJobClearController::class, 'destroy'])
    ->middleware(horizonAbility('manageBatches'))
    ->name('batches.failed.clear.destroy');

Route::get('/queues', [QueueController::class, 'index'])->name('queues.index');
Route::delete('/queues', [QueueClearAllController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->name('queues.clear-all.destroy');
Route::post('/queues/pause-all', [QueuePauseAllController::class, 'store'])
    ->middleware([EnsureQueuePausingAllIsSupported::class, horizonAbility('pauseQueues')])
    ->name('queues.pause-all.store');
Route::delete('/queues/pause-all', [QueuePauseAllController::class, 'destroy'])
    ->middleware([EnsureQueuePausingAllIsSupported::class, horizonAbility('pauseQueues')])
    ->name('queues.pause-all.destroy');
Route::get('/queues/{queue}', [QueueController::class, 'show'])
    ->where('queue', '.+')
    ->name('queues.show');
Route::post('/queues/{connection}/{queue}/pause', [QueuePauseController::class, 'store'])
    ->middleware([EnsureQueuePausingIsSupported::class, horizonAbility('pauseQueues')])
    ->where('queue', '.+?')
    ->name('queues.pause.store');
Route::delete('/queues/{connection}/{queue}/pause', [QueuePauseController::class, 'destroy'])
    ->middleware([EnsureQueuePausingIsSupported::class, horizonAbility('pauseQueues')])
    ->where('queue', '.+?')
    ->name('queues.pause.destroy');
Route::delete('/queues/{connection}/{queue}/clear', [QueueClearController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->where('queue', '.+?')
    ->name('queues.clear.destroy');
Route::post('/queues/{connection}/{queue}/retry-failed', [QueueFailedJobRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->where('queue', '.+?')
    ->name('queues.retry-failed.store');
Route::post('/queues/{queue}/batches/retry-failed-jobs', [QueueBatchRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->where('queue', '.+?')
    ->name('queues.batches.retry-failed.store');

Route::delete('/jobs/pending', [PendingJobClearAllController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->name('jobs.pending.clear.destroy');
Route::delete('/jobs/pending/cancel/{scope}', [PendingJobsCancellationController::class, 'destroy'])
    ->middleware(horizonAbility('cancelJobs'))
    ->where('scope', 'ready|delayed|pending')
    ->name('jobs.pending.cancel.destroy');
Route::delete('/jobs/pending/cancel-selected', [PendingJobsSelectedCancelController::class, 'destroy'])
    ->middleware(horizonAbility('cancelJobs'))
    ->name('jobs.pending.cancel-selected.destroy');
Route::post('/jobs/pending/{job}/release', [DelayedJobReleaseController::class, 'store'])
    ->middleware(horizonAbility('cancelJobs'))
    ->name('jobs.pending.release.store');
Route::delete('/jobs/pending/{job}', [PendingJobController::class, 'destroy'])
    ->middleware(horizonAbility('cancelJobs'))
    ->name('jobs.pending.destroy');
Route::get('/jobs/{type}', [JobController::class, 'index'])
    ->where('type', 'pending|completed|silenced')
    ->name('jobs.index');
Route::get('/jobs/{type}/{job}', [JobController::class, 'show'])
    ->where('type', 'pending|completed|silenced')
    ->name('jobs.show');
Route::post('/jobs/{type}/{job}/retry', [JobRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->where('type', 'completed|silenced')
    ->name('jobs.retry.store');

Route::get('/failed', [FailedJobController::class, 'index'])->name('failed-jobs.index');
Route::delete('/failed', [FailedJobClearAllController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->name('failed-jobs.clear-all.destroy');
Route::post('/failed/retry-all', [FailedJobRetryAllController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->name('failed-jobs.retry-all.store');
Route::post('/failed/retry-selected', [FailedJobsSelectedRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->name('failed-jobs.retry-selected.store');
Route::delete('/failed/selected', [FailedJobsSelectedClearController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->name('failed-jobs.selected.destroy');
Route::get('/failed/{job}', [FailedJobController::class, 'show'])->name('failed-jobs.show');
Route::delete('/failed/{job}', [FailedJobController::class, 'destroy'])
    ->middleware(horizonAbility('clearQueues'))
    ->name('failed-jobs.destroy');
Route::post('/failed/{job}/retry', [FailedJobRetryController::class, 'store'])
    ->middleware(horizonAbility('retryJobs'))
    ->name('failed-jobs.retry.store');
Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
Route::post('/schedule/{event}/run', [ScheduleRunController::class, 'store'])
    ->middleware(horizonAbility('manageSchedule'))
    ->name('schedule.run.store');
Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
Route::get('/workflows/{workflow}', [WorkflowController::class, 'show'])->name('workflows.show');
Route::post('/workflows/{workflow}/cancel', [WorkflowCancelController::class, 'store'])
    ->middleware(horizonAbility('manageWorkflows'))
    ->name('workflows.cancel.store');
Route::post('/workflows/{workflow}/retry', [WorkflowRetryController::class, 'store'])
    ->middleware(horizonAbility('manageWorkflows'))
    ->name('workflows.retry.store');
