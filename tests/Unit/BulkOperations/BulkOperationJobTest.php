<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\ClearFailedJobsJob;

it('inherits the Horizon worker timeout', function (): void {
    config()->set('horizon-new-dawn.bulk_operations.timeout', 180);

    $jobClass = ClearFailedJobsJob::class;

    expect((new ReflectionClass($jobClass))->hasProperty('timeout'))->toBeFalse();
});

it('logs structured feedback when a bulk operation fails after dispatch', function (): void {
    config()->set('horizon-new-dawn.bulk_operations.connection', 'operations');
    config()->set('horizon-new-dawn.bulk_operations.queue', 'horizon-maintenance');

    $exception = new RuntimeException('Redis is unavailable.');

    Log::shouldReceive('error')
        ->once()
        ->with('Horizon New Dawn bulk operation failed.', [
            'job' => ClearFailedJobsJob::class,
            'connection' => 'operations',
            'queue' => 'horizon-maintenance',
            'exception' => $exception,
        ]);

    (new ClearFailedJobsJob)->failed($exception);
});
