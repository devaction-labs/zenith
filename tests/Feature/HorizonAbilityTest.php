<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('forbids queue pauses when the pauseQueues gate is denied', function (): void {
    requireQueuePausing();
    Gate::define('zenith.pauseQueues', static fn (): bool => false);

    post('/horizon/queues/redis/reports/pause')->assertForbidden();
});

it('forbids failed-job retries when the retryJobs gate is denied', function (): void {
    Gate::define('zenith.retryJobs', static fn (): bool => false);

    post('/horizon/failed/job-1/retry')->assertForbidden();
});
