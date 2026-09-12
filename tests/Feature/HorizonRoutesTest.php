<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

describe('Zenith routes', function (): void {
    it('registers concrete interface routes under the Horizon boundary', function (): void {
        $expected = [
            'zenith.dashboard' => ['GET', 'horizon'],
            'zenith.dashboard.index' => ['GET', 'horizon/dashboard'],
            'zenith.instances.index' => ['GET', 'horizon/instances'],
            'zenith.instances.terminate.store' => ['POST', 'horizon/instances/terminate'],
            'zenith.instances.pause.store' => ['POST', 'horizon/instances/{instance}/pause'],
            'zenith.instances.pause.destroy' => ['DELETE', 'horizon/instances/{instance}/pause'],
            'zenith.supervisors.pause.store' => ['POST', 'horizon/supervisors/{supervisor}/pause'],
            'zenith.supervisors.pause.destroy' => ['DELETE', 'horizon/supervisors/{supervisor}/pause'],
            'zenith.monitoring.index' => ['GET', 'horizon/monitoring'],
            'zenith.monitoring.store' => ['POST', 'horizon/monitoring'],
            'zenith.monitoring.show' => ['GET', 'horizon/monitoring/{tag}/{status?}'],
            'zenith.monitoring.destroy' => ['DELETE', 'horizon/monitoring/actions/stop/{tag}'],
            'zenith.monitoring.jobs.destroy' => ['DELETE', 'horizon/monitoring/actions/clear-jobs/{tag}'],
            'zenith.monitoring.retry-failed.store' => ['POST', 'horizon/monitoring/actions/retry-failed/{tag}'],
            'zenith.metrics.redirect' => ['GET', 'horizon/metrics'],
            'zenith.metrics.index' => ['GET', 'horizon/metrics/{type}'],
            'zenith.metrics.show' => ['GET', 'horizon/metrics/{type}/{slug}'],
            'zenith.batches.index' => ['GET', 'horizon/batches'],
            'zenith.batches.show' => ['GET', 'horizon/batches/{batch}'],
            'zenith.batches.retry.store' => ['POST', 'horizon/batches/{batch}/retry'],
            'zenith.queues.index' => ['GET', 'horizon/queues'],
            'zenith.queues.clear-all.destroy' => ['DELETE', 'horizon/queues'],
            'zenith.queues.pause-all.store' => ['POST', 'horizon/queues/pause-all'],
            'zenith.queues.pause-all.destroy' => ['DELETE', 'horizon/queues/pause-all'],
            'zenith.queues.show' => ['GET', 'horizon/queues/{queue}'],
            'zenith.queues.pause.store' => ['POST', 'horizon/queues/{connection}/{queue}/pause'],
            'zenith.queues.pause.destroy' => ['DELETE', 'horizon/queues/{connection}/{queue}/pause'],
            'zenith.queues.clear.destroy' => ['DELETE', 'horizon/queues/{connection}/{queue}/clear'],
            'zenith.jobs.index' => ['GET', 'horizon/jobs/{type}'],
            'zenith.jobs.show' => ['GET', 'horizon/jobs/{type}/{job}'],
            'zenith.jobs.pending.destroy' => ['DELETE', 'horizon/jobs/pending/{job}'],
            'zenith.failed-jobs.index' => ['GET', 'horizon/failed'],
            'zenith.failed-jobs.clear-all.destroy' => ['DELETE', 'horizon/failed'],
            'zenith.failed-jobs.retry-all.store' => ['POST', 'horizon/failed/retry-all'],
            'zenith.failed-jobs.show' => ['GET', 'horizon/failed/{job}'],
            'zenith.failed-jobs.destroy' => ['DELETE', 'horizon/failed/{job}'],
            'zenith.failed-jobs.retry.store' => ['POST', 'horizon/failed/{job}/retry'],
            'zenith.audit.index' => ['GET', 'horizon/audit'],
            'zenith.schedule.index' => ['GET', 'horizon/schedule'],
            'zenith.schedule.run.store' => ['POST', 'horizon/schedule/{event}/run'],
            'zenith.workflows.index' => ['GET', 'horizon/workflows'],
            'zenith.workflows.show' => ['GET', 'horizon/workflows/{workflow}'],
            'zenith.workflows.cancel.store' => ['POST', 'horizon/workflows/{workflow}/cancel'],
            'zenith.workflows.retry.store' => ['POST', 'horizon/workflows/{workflow}/retry'],
        ];

        foreach ($expected as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            expect($route)
                ->not->toBeNull()
                ->and($route?->methods())->toContain($method)
                ->and($route?->uri())->toBe($uri)
                ->and($route?->middleware())->toContain('horizon')
                ->and($route?->gatherMiddleware())->toContain(Authenticate::class)
                ->and($route?->gatherMiddleware())->toContain(HandleInertiaRequests::class);
        }
    });

    it('matches concrete routes before the Horizon catch-all', function (): void {
        $dashboard = Route::getRoutes()->match(Request::create('/horizon', 'GET'));
        $metrics = Route::getRoutes()->match(Request::create('/horizon/metrics/jobs', 'GET'));
        $retryAll = Route::getRoutes()->match(Request::create('/horizon/failed/retry-all', 'POST'));

        expect($dashboard->getName())->toBe('zenith.dashboard')
            ->and($metrics->getName())->toBe('zenith.metrics.index')
            ->and($retryAll->getName())->toBe('zenith.failed-jobs.retry-all.store');
    });

    it('exposes queue-wide clearing and individual pending cancellation', function (): void {
        $clearAll = Route::getRoutes()->match(Request::create('/horizon/jobs/pending', 'DELETE'));
        $cancel = Route::getRoutes()->match(Request::create(
            '/horizon/jobs/pending/pending-1',
            'DELETE',
        ));

        expect($clearAll->getName())->toBe('zenith.jobs.pending.clear.destroy')
            ->and($cancel->getName())->toBe('zenith.jobs.pending.destroy');
    });

    it('matches encoded slash-bearing monitored tags without consuming action segments', function (): void {
        $show = Route::getRoutes()->match(Request::create('/horizon/monitoring/customer%2F42/jobs', 'GET'));
        $clear = Route::getRoutes()->match(Request::create('/horizon/monitoring/actions/clear-jobs/customer%2Fjobs', 'DELETE'));
        $retry = Route::getRoutes()->match(Request::create('/horizon/monitoring/actions/retry-failed/customer%2F42', 'POST'));
        $stop = Route::getRoutes()->match(Request::create('/horizon/monitoring/actions/stop/jobs%2Fcustomer', 'DELETE'));

        expect($show->getName())->toBe('zenith.monitoring.show')
            ->and($show->parameter('tag'))->toBe('customer/42')
            ->and($show->parameter('status'))->toBe('jobs')
            ->and($clear->getName())->toBe('zenith.monitoring.jobs.destroy')
            ->and($clear->parameter('tag'))->toBe('customer/jobs')
            ->and($retry->getName())->toBe('zenith.monitoring.retry-failed.store')
            ->and($retry->parameter('tag'))->toBe('customer/42')
            ->and($stop->getName())->toBe('zenith.monitoring.destroy')
            ->and($stop->parameter('tag'))->toBe('jobs/customer')
            ->and(fn () => Route::getRoutes()->match(
                Request::create('/horizon/monitoring/customer%2Fjobs', 'DELETE'),
            ))->toThrow(MethodNotAllowedHttpException::class);
    });

    it('matches encoded slash-bearing queue names without consuming action suffixes', function (): void {
        $show = Route::getRoutes()->match(Request::create('/horizon/queues/reports%2Fdaily', 'GET'));
        $pause = Route::getRoutes()->match(Request::create('/horizon/queues/redis/reports%2Fdaily/pause', 'POST'));
        $resume = Route::getRoutes()->match(Request::create('/horizon/queues/redis/reports%2Fdaily/pause', 'DELETE'));
        $clear = Route::getRoutes()->match(Request::create('/horizon/queues/redis/reports%2Fdaily/clear', 'DELETE'));
        $retry = Route::getRoutes()->match(Request::create('/horizon/queues/redis/reports%2Fdaily/retry-failed', 'POST'));
        $retryBatches = Route::getRoutes()->match(Request::create(
            '/horizon/queues/reports%2Fdaily/batches/retry-failed-jobs',
            'POST',
        ));

        expect($show->getName())->toBe('zenith.queues.show')
            ->and($show->parameter('queue'))->toBe('reports/daily')
            ->and($pause->getName())->toBe('zenith.queues.pause.store')
            ->and($pause->parameter('queue'))->toBe('reports/daily')
            ->and($resume->getName())->toBe('zenith.queues.pause.destroy')
            ->and($resume->parameter('queue'))->toBe('reports/daily')
            ->and($clear->getName())->toBe('zenith.queues.clear.destroy')
            ->and($clear->parameter('queue'))->toBe('reports/daily')
            ->and($retry->getName())->toBe('zenith.queues.retry-failed.store')
            ->and($retry->parameter('queue'))->toBe('reports/daily')
            ->and($retryBatches->getName())->toBe('zenith.queues.batches.retry-failed.store')
            ->and($retryBatches->parameter('queue'))->toBe('reports/daily');
    });

    it('matches encoded slash-bearing metric and supervisor identifiers', function (): void {
        $metric = Route::getRoutes()->match(Request::create('/horizon/metrics/jobs/App%5CJobs%5CImport%2FOrders', 'GET'));
        $supervisorShow = Route::getRoutes()->match(Request::create('/horizon/supervisors/local-host-a1b2%3Aimports%2Fworker', 'GET'));
        $supervisorPause = Route::getRoutes()->match(Request::create('/horizon/supervisors/local-host-a1b2%3Aimports%2Fworker/pause', 'POST'));
        $supervisorContinue = Route::getRoutes()->match(Request::create('/horizon/supervisors/local-host-a1b2%3Aimports%2Fworker/pause', 'DELETE'));

        expect($metric->getName())->toBe('zenith.metrics.show')
            ->and($metric->parameter('slug'))->toBe('App\\Jobs\\Import/Orders')
            ->and($supervisorShow->getName())->toBe('zenith.supervisors.show')
            ->and($supervisorShow->parameter('supervisor'))->toBe('local-host-a1b2:imports/worker')
            ->and($supervisorPause->getName())->toBe('zenith.supervisors.pause.store')
            ->and($supervisorPause->parameter('supervisor'))->toBe('local-host-a1b2:imports/worker')
            ->and($supervisorContinue->getName())->toBe('zenith.supervisors.pause.destroy')
            ->and($supervisorContinue->parameter('supervisor'))->toBe('local-host-a1b2:imports/worker');
    });

    it('constrains route-backed interface states', function (): void {
        $metrics = Route::getRoutes()->getByName('zenith.metrics.index');
        $metricShow = Route::getRoutes()->getByName('zenith.metrics.show');
        $jobs = Route::getRoutes()->getByName('zenith.jobs.index');
        $monitoring = Route::getRoutes()->getByName('zenith.monitoring.show');
        $monitoringClear = Route::getRoutes()->getByName('zenith.monitoring.jobs.destroy');
        $monitoringRetry = Route::getRoutes()->getByName('zenith.monitoring.retry-failed.store');
        $monitoringStop = Route::getRoutes()->getByName('zenith.monitoring.destroy');
        $supervisorShow = Route::getRoutes()->getByName('zenith.supervisors.show');
        $supervisorPause = Route::getRoutes()->getByName('zenith.supervisors.pause.store');
        $supervisorContinue = Route::getRoutes()->getByName('zenith.supervisors.pause.destroy');

        expect($metrics?->wheres['type'] ?? null)->toBe('jobs|queues')
            ->and($metricShow?->wheres['slug'] ?? null)->toBe('.+')
            ->and($jobs?->wheres['type'] ?? null)->toBe('pending|completed|silenced')
            ->and($monitoring?->wheres['tag'] ?? null)->toBe('.+?')
            ->and($monitoring?->wheres['status'] ?? null)->toBe('jobs|failed')
            ->and($monitoringClear?->wheres['tag'] ?? null)->toBe('.+')
            ->and($monitoringRetry?->wheres['tag'] ?? null)->toBe('.+')
            ->and($monitoringStop?->wheres['tag'] ?? null)->toBe('.+')
            ->and($supervisorShow?->wheres['supervisor'] ?? null)->toBe('.+')
            ->and($supervisorPause?->wheres['supervisor'] ?? null)->toBe('.+?')
            ->and($supervisorContinue?->wheres['supervisor'] ?? null)->toBe('.+?');
    });
});
