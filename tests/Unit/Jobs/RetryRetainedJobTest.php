<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\Actions\RetryRetainedJob;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobRetryResult;
use DevactionLabs\Zenith\Tests\Support\HorizonJob;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

final class RetryableRetainedProbeJob {}

final class UniqueRetainedProbeJob implements ShouldBeUnique {}

function bindRetainedJob(HorizonJob $job): JobRepository
{
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'getJobs', [[$job->id]], new Collection([$job]));

    return $repository;
}

function retainedProbeJob(string $id, string $commandClass, string $status = 'completed'): HorizonJob
{
    $job = horizonJob(0, $id);
    $job->status = $status;
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    data_set($payload, 'data.commandName', $commandClass);
    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);

    return $job;
}

it('re-dispatches the retained payload as a brand-new job', function (): void {
    $job = retainedProbeJob('completed-1', RetryableRetainedProbeJob::class);
    $repository = bindRetainedJob($job);

    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('pushRaw')->once()->withArgs(
        function (string $payload, string $queueName): bool {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
                throw new LogicException('The retried payload must decode to an array.');
            }

            expect($decoded['data']['commandName'])->toBe(RetryableRetainedProbeJob::class)
                ->and($decoded['attempts'])->toBe(0)
                ->and($decoded['retry_of'])->toBe('completed-1')
                ->and($decoded['id'])->not->toBe('completed-1')
                ->and($decoded['uuid'])->toBe($decoded['id'])
                ->and($queueName)->toBe('default');

            return true;
        },
    );

    $factory = mockDashboardContract(QueueFactory::class);
    dashboardReturnsFor($factory, 'connection', ['redis'], $queue);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('completed-1'))->toBe(RetainedJobRetryResult::Retried);
});

it('retries a silenced job the same way, since both share the completed status', function (): void {
    $job = retainedProbeJob('silenced-1', RetryableRetainedProbeJob::class);
    $repository = bindRetainedJob($job);

    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('pushRaw')->once();

    $factory = mockDashboardContract(QueueFactory::class);
    dashboardReturnsFor($factory, 'connection', ['redis'], $queue);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('silenced-1'))->toBe(RetainedJobRetryResult::Retried);
});

it('refuses a job id that is no longer retained', function (): void {
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'getJobs', [['missing']], new Collection([]));
    $factory = mockDashboardContract(QueueFactory::class);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('missing'))->toBe(RetainedJobRetryResult::NotRetained);
});

it('refuses a pending job id that has not finished yet', function (): void {
    $job = retainedProbeJob('pending-1', RetryableRetainedProbeJob::class, status: 'pending');
    $repository = bindRetainedJob($job);
    $factory = mockDashboardContract(QueueFactory::class);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('pending-1'))->toBe(RetainedJobRetryResult::NotRetained);
});

it('refuses a retained job whose class no longer exists', function (): void {
    $job = retainedProbeJob('completed-2', 'App\\Jobs\\LongRemoved');
    $repository = bindRetainedJob($job);
    $factory = mockDashboardContract(QueueFactory::class);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('completed-2'))->toBe(RetainedJobRetryResult::ClassMissing);
});

it('refuses a retained job whose class enforces uniqueness', function (): void {
    $job = retainedProbeJob('completed-3', UniqueRetainedProbeJob::class);
    $repository = bindRetainedJob($job);
    $factory = mockDashboardContract(QueueFactory::class);

    $action = new RetryRetainedJob(new JobsData($repository), $factory);

    expect($action->handle('completed-3'))->toBe(RetainedJobRetryResult::UniqueOrDebounced);
});
