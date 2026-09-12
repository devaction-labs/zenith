<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\Jobs\ClearFailedJobsJob;
use Illuminate\Support\Facades\Log;

it('inherits the Horizon worker timeout', function (): void {
    config()->set('zenith.bulk_operations.timeout', 180);

    $jobClass = ClearFailedJobsJob::class;

    expect((new ReflectionClass($jobClass))->hasProperty('timeout'))->toBeFalse();
});

it('logs structured feedback when a bulk operation fails after dispatch', function (): void {
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $exception = new RuntimeException('Redis is unavailable.');

    Log::shouldReceive('error')
        ->once()
        ->with('Zenith bulk operation failed.', [
            'job' => ClearFailedJobsJob::class,
            'connection' => 'operations',
            'queue' => 'horizon-maintenance',
            'exception' => $exception,
        ]);

    (new ClearFailedJobsJob)->failed($exception);
});
