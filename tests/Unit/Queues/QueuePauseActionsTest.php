<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\HorizonNewDawn\Queues\Actions\PauseAllQueues;
use DevactionLabs\HorizonNewDawn\Queues\Actions\PauseQueue;
use DevactionLabs\HorizonNewDawn\Queues\Actions\ResumeAllQueues;
use DevactionLabs\HorizonNewDawn\Queues\Actions\ResumeQueue;
use DevactionLabs\HorizonNewDawn\Queues\Data\PauseQueueData;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseMetadata;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Queue\QueueManager;

beforeEach(function (): void {
    requireQueuePausing();

    app(CacheFactory::class)->store()->clear();
    CarbonImmutable::setTestNow('2026-07-20 18:00:00 UTC');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

describe('queue pause actions', function (): void {
    it('pauses a queue indefinitely and clears an earlier deadline', function (): void {
        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $metadata->storeUntil('redis', 'reports', CarbonImmutable::now()->addHour());
        $queues = app(QueueManager::class);

        $deadline = (new PauseQueue($queues, $metadata))->handle(
            new PauseQueueData('redis', 'reports', null),
        );

        expect($deadline)->toBeNull()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($metadata->pausedUntil('redis', 'reports'))->toBeNull();
    });

    it('sets a timed pause from now and replaces the readable deadline', function (): void {
        requireTimedQueuePausing();

        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $queues = app(QueueManager::class);
        $expectedDeadline = CarbonImmutable::now()->addHour();

        $deadline = (new PauseQueue($queues, $metadata))->handle(
            new PauseQueueData('redis', 'reports', 60),
        );

        expect($deadline?->equalTo($expectedDeadline))->toBeTrue()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($metadata->pausedUntil('redis', 'reports'))->toBe($expectedDeadline->timestamp);
    });

    it('falls back to an indefinite pause when a duration is supplied without timed support', function (): void {
        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $metadata->storeUntil('redis', 'reports', CarbonImmutable::now()->addHour());
        $queues = app(QueueManager::class);
        $capabilities = new FrameworkCapabilities(queuePausing: true, timedQueuePausing: false);

        $deadline = (new PauseQueue($queues, $metadata, $capabilities))->handle(
            new PauseQueueData('redis', 'reports', 30),
        );

        expect($deadline)->toBeNull()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($metadata->pausedUntil('redis', 'reports'))->toBeNull();
    });

    it('resumes a queue and removes its readable deadline', function (): void {
        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $metadata->storeUntil('redis', 'reports', CarbonImmutable::now()->addHour());
        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');

        (new ResumeQueue($queues, $metadata))->handle('redis', 'reports');

        expect($queues->isPaused('redis', 'reports'))->toBeFalse()
            ->and($metadata->pausedUntil('redis', 'reports'))->toBeNull();
    });

    it('pauses every queue without clearing an individual pause deadline', function (): void {
        requireQueuePausingAll();

        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $deadline = CarbonImmutable::now()->addHour();
        $metadata->storeUntil('redis', 'reports', $deadline);
        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');

        (new PauseAllQueues($queues))->handle();

        expect($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($queues->isPaused('redis', 'mail'))->toBeTrue()
            ->and($metadata->pausedUntil('redis', 'reports'))->toBe($deadline->timestamp)
            ->and($metadata->laravelGlobalPauseActive())->toBeTrue();
    });

    it('clears only the global pause so individually paused queues stay paused', function (): void {
        requireQueuePausingAll();

        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');
        $queues->pauseAll();

        (new ResumeAllQueues($queues))->handle();

        expect($metadata->laravelGlobalPauseActive())->toBeFalse()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue()
            ->and($queues->isPaused('redis', 'mail'))->toBeFalse();
    });
});
