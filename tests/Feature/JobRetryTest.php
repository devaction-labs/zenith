<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Audit\HorizonAuditEvent;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

final class JobRetryFeatureProbe {}

final class UniqueJobRetryFeatureProbe implements ShouldBeUnique {}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

function bindRetainedJobForRetry(string $id, string $commandClass, string $status = 'completed'): void
{
    $job = horizonJob(0, $id);
    $job->status = $status;
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        throw new LogicException('The Horizon job payload must decode to an array.');
    }

    data_set($payload, 'data.commandName', $commandClass);
    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'getJobs', [[$id]], new Collection([$job]));
    app()->instance(JobRepository::class, $repository);
}

function bindRetryQueueConnection(string $connection = 'redis'): void
{
    $queue = mockDashboardContract(Queue::class);
    dashboardReturnsFor($queue, 'pushRaw', [Mockery::type('string'), 'default'], null);

    $factory = mockDashboardContract(QueueFactory::class);
    dashboardReturnsFor($factory, 'connection', [$connection], $queue);
    app()->instance(QueueFactory::class, $factory);
}

it('retries a retained completed job', function (): void {
    bindRetainedJobForRetry('completed-1', JobRetryFeatureProbe::class);
    bindRetryQueueConnection();

    post('/horizon/jobs/completed/completed-1/retry')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Retry scheduled for completed-1.');
});

it('retries a retained silenced job through the same endpoint', function (): void {
    bindRetainedJobForRetry('silenced-1', JobRetryFeatureProbe::class);
    bindRetryQueueConnection();

    post('/horizon/jobs/silenced/silenced-1/retry')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Retry scheduled for silenced-1.');
});

it('refuses to retry a job that enforces uniqueness', function (): void {
    bindRetainedJobForRetry('completed-2', UniqueJobRetryFeatureProbe::class);

    post('/horizon/jobs/completed/completed-2/retry')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'No retry was scheduled because completed-2 enforces uniqueness or debouncing and must be re-dispatched by the application instead.',
        );
});

it('reports a job id that is no longer retained', function (): void {
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'getJobs', [['missing']], new Collection);
    app()->instance(JobRepository::class, $repository);

    post('/horizon/jobs/completed/missing/retry')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'No retry was scheduled because missing is no longer retained.',
        );
});

it('forbids retrying a job when the retryJobs gate is denied', function (): void {
    Gate::define('zenith.retryJobs', static fn (): bool => false);

    post('/horizon/jobs/completed/completed-1/retry')->assertForbidden();
});

it('honors Horizon authorization', function (): void {
    Horizon::auth(static fn (): bool => false);

    post('/horizon/jobs/completed/completed-1/retry')->assertForbidden();
});

it('records the retry as an audited mutation', function (): void {
    Schema::dropIfExists('zenith_audit_events');
    Schema::create('zenith_audit_events', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('occurred_at')->index();
        $table->string('action', 128);
        $table->string('route', 128);
        $table->string('user_id')->nullable();
        $table->string('ip', 45)->nullable();
        $table->json('context')->nullable();
    });

    bindRetainedJobForRetry('completed-3', JobRetryFeatureProbe::class);
    bindRetryQueueConnection();

    post('/horizon/jobs/completed/completed-3/retry')->assertRedirect();

    expect(
        HorizonAuditEvent::query()
            ->where('route', 'zenith.jobs.retry.store')
            ->where('context->job', 'completed-3')
            ->count(),
    )->toBeGreaterThan(0);
});
