<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\delete;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    app(CacheFactory::class)->store()->clear();
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

describe('queue pause mutations', function (): void {
    it('returns not found when the installed framework cannot pause queues', function (): void {
        app()->instance(FrameworkCapabilities::class, new FrameworkCapabilities(queuePausing: false));

        post('/horizon/queues/redis/reports/pause')->assertNotFound();
        post('/horizon/queues/unsupported/reports/pause')->assertNotFound();
        delete('/horizon/queues/redis/reports/pause')->assertNotFound();
    });

    it('pauses a queue indefinitely', function (): void {
        requireQueuePausing();

        $queues = app(QueueManager::class);

        post('/horizon/queues/redis/reports/pause')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Paused reports indefinitely.');

        expect($queues->isPaused('redis', 'reports'))->toBeTrue();
    });

    it('pauses a queue for a custom duration', function (): void {
        requireTimedQueuePausing();

        $queues = app(QueueManager::class);

        post('/horizon/queues/redis/reports/pause', ['duration_minutes' => 30])
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Paused reports for 30 minutes.');

        expect($queues->isPaused('redis', 'reports'))->toBeTrue();
    });

    it('pauses indefinitely when timed pausing is unavailable even if a duration is supplied', function (): void {
        requireQueuePausing();

        app()->instance(
            FrameworkCapabilities::class,
            new FrameworkCapabilities(queuePausing: true, timedQueuePausing: false),
        );

        $queues = app(QueueManager::class);

        post('/horizon/queues/redis/reports/pause', ['duration_minutes' => 30])
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Paused reports indefinitely.');

        expect($queues->isPaused('redis', 'reports'))->toBeTrue();
    });

    it('passes an encoded slash-bearing queue name to the mutation action', function (): void {
        requireQueuePausing();

        $queues = app(QueueManager::class);

        post('/horizon/queues/redis/reports%2Fdaily/pause')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Paused reports/daily indefinitely.');

        expect($queues->isPaused('redis', 'reports/daily'))->toBeTrue();
    });

    it('resumes a paused queue', function (): void {
        requireQueuePausing();

        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');

        delete('/horizon/queues/redis/reports/pause')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'The reports queue has been signaled to resume.');

        expect($queues->isPaused('redis', 'reports'))->toBeFalse();
    });

    it('rejects unsupported connections and invalid durations', function (): void {
        requireQueuePausing();

        post('/horizon/queues/unsupported/reports/pause')
            ->assertSessionHasErrors('connection');

        post('/horizon/queues/redis/reports/pause', ['duration_minutes' => 0])
            ->assertSessionHasErrors('duration_minutes');
    });

    it('honors Horizon authorization', function (): void {
        requireQueuePausing();

        Horizon::auth(static fn (): bool => false);

        post('/horizon/queues/redis/reports/pause')->assertForbidden();
        delete('/horizon/queues/redis/reports/pause')->assertForbidden();
    });
});

describe('global queue pause mutations', function (): void {
    it('returns not found when the installed framework cannot pause all queues', function (): void {
        app()->instance(
            FrameworkCapabilities::class,
            new FrameworkCapabilities(queuePausing: true, queuePausingAll: false),
        );

        post('/horizon/queues/pause-all')->assertNotFound();
        delete('/horizon/queues/pause-all')->assertNotFound();
    });

    it('pauses every queue on every connection', function (): void {
        requireQueuePausingAll();

        $queues = app(QueueManager::class);

        post('/horizon/queues/pause-all')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Paused all queues on every connection.');

        expect($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($queues->isPaused('redis', 'mail'))->toBeTrue();
    });

    it('clears the global pause without resuming individually paused queues', function (): void {
        requireQueuePausingAll();

        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');
        $queues->pauseAll();

        delete('/horizon/queues/pause-all')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.success',
                'Cleared the global queue pause. Individually paused queues remain paused.',
            );

        expect($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($queues->isPaused('redis', 'mail'))->toBeFalse();
    });

    it('honors Horizon authorization for global pause', function (): void {
        requireQueuePausingAll();

        Horizon::auth(static fn (): bool => false);

        post('/horizon/queues/pause-all')->assertForbidden();
        delete('/horizon/queues/pause-all')->assertForbidden();
    });
});
