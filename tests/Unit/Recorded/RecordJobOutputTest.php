<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Recorded\Attributes\Recorded as RecordedAttribute;
use DevactionLabs\Zenith\Recorded\Recorded;
use DevactionLabs\Zenith\Recorded\RecordJobOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;

#[RecordedAttribute]
final class RecordedOutputJob
{
    use InteractsWithQueue, Queueable;
}

final class UnrecordedOutputJob
{
    use InteractsWithQueue, Queueable;
}

function recordedTestQueueJob(): SyncJob
{
    return new SyncJob(app(), json_encode(['uuid' => (string) Str::uuid(), 'data' => []], JSON_THROW_ON_ERROR), 'sync', 'default');
}

it('records the return value of a job carrying the Recorded attribute', function (): void {
    $middleware = new RecordJobOutput;
    $queueJob = recordedTestQueueJob();
    $job = (new RecordedOutputJob)->setJob($queueJob);

    $result = $middleware->handle($job, fn (object $job): array => ['ok' => true]);

    expect($result)->toBe(['ok' => true])
        ->and(Recorded::get((string) $queueJob->uuid()))->toBe(['ok' => true]);
});

it('does not record the return value of a job without the attribute', function (): void {
    $middleware = new RecordJobOutput;
    $queueJob = recordedTestQueueJob();
    $job = (new UnrecordedOutputJob)->setJob($queueJob);

    $result = $middleware->handle($job, fn (object $job): array => ['ok' => true]);

    expect($result)->toBe(['ok' => true])
        ->and(Recorded::get((string) $queueJob->uuid()))->toBeNull();
});

it('runs a recorded job with no underlying queue job straight through', function (): void {
    $middleware = new RecordJobOutput;

    $result = $middleware->handle(new RecordedOutputJob, fn (object $job): string => 'ran');

    expect($result)->toBe('ran');
});
