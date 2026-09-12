<?php

declare(strict_types=1);

use DevactionLabs\Zenith\History\JobHistory;
use DevactionLabs\Zenith\History\JobHistoryStatus;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

use function DevactionLabs\Zenith\Tests\Support\fakeQueueJob;

function runJobHistoryMigration(string $command): void
{
    Artisan::call($command, [
        '--path' => dirname(__DIR__, 3).'/database/migrations/2026_08_30_030000_create_zenith_job_history_table.php',
        '--realpath' => true,
    ]);
}

beforeEach(function (): void {
    Schema::dropIfExists('zenith_job_history');
    runJobHistoryMigration('migrate:refresh');
});

afterEach(function (): void {
    runJobHistoryMigration('migrate:reset');
    config(['zenith.history.enabled' => false]);
});

it('records nothing while history recording is disabled', function (): void {
    config(['zenith.history.enabled' => false]);

    $job = fakeQueueJob([
        'displayName' => 'App\\Jobs\\SendReport',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'tags' => ['tenant:1'],
    ]);

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(JobHistory::query()->count())->toBe(0);
});

it('records a completed job with its runtime, tags, and pushed-at timestamp', function (): void {
    config(['zenith.history.enabled' => true]);

    $pushedAt = now()->subMinute()->getTimestamp();

    $job = fakeQueueJob([
        'displayName' => 'App\\Jobs\\SendReport',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'tags' => ['tenant:1', 'reports'],
        'createdAt' => $pushedAt,
    ], connectionName: 'redis', queueName: 'reports', jobId: 'job-1', attemptCount: 1);

    event(new JobProcessing('redis', $job));
    usleep(2_000);
    event(new JobProcessed('redis', $job));

    $record = JobHistory::query()->firstOrFail();

    expect($record->job_class)->toBe('App\\Jobs\\SendReport')
        ->and($record->queue)->toBe('reports')
        ->and($record->connection)->toBe('redis')
        ->and($record->status)->toBe(JobHistoryStatus::Completed)
        ->and($record->attempts)->toBe(1)
        ->and($record->runtime_ms)->toBeGreaterThanOrEqual(1)
        ->and($record->tags)->toBe(['tenant:1', 'reports'])
        ->and($record->error)->toBeNull()
        ->and($record->pushed_at?->getTimestamp())->toBe($pushedAt)
        ->and($record->completed_at)->not->toBeNull()
        ->and($record->failed_at)->toBeNull();
});

it('records a silenced job as its own status', function (): void {
    config(['zenith.history.enabled' => true]);

    $job = fakeQueueJob([
        'displayName' => 'App\\Jobs\\SendReport',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'silenced' => true,
    ]);

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(JobHistory::query()->firstOrFail()->status)->toBe(JobHistoryStatus::Silenced);
});

it('records a failed job with a truncated error summary and no completed-at timestamp', function (): void {
    config(['zenith.history.enabled' => true]);

    $job = fakeQueueJob([
        'displayName' => 'App\\Jobs\\SendReport',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
    ], attemptCount: 3);

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new RuntimeException('The report service is unreachable.')));

    $record = JobHistory::query()->firstOrFail();

    expect($record->status)->toBe(JobHistoryStatus::Failed)
        ->and($record->attempts)->toBe(3)
        ->and($record->error)->toBe('RuntimeException: The report service is unreachable.')
        ->and($record->completed_at)->toBeNull()
        ->and($record->failed_at)->not->toBeNull();
});

it('does nothing when the history table is missing even if recording is enabled', function (): void {
    config(['zenith.history.enabled' => true]);
    Schema::dropIfExists('zenith_job_history');

    $job = fakeQueueJob([
        'displayName' => 'App\\Jobs\\SendReport',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
    ]);

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(Schema::hasTable('zenith_job_history'))->toBeFalse();
});
