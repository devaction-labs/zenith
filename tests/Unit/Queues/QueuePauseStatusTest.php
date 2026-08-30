<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseMetadata;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseStatus;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Queue\QueueManager;

use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 18:00:00 UTC');
    app(CacheFactory::class)->store()->clear();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

describe('QueuePauseStatus', function (): void {
    it('does not inspect pause state when the framework cannot pause queues', function (): void {
        $queues = mockDashboardContract(QueueManager::class);
        $queues->shouldNotReceive('isPaused');

        $state = (new QueuePauseStatus(
            queues: $queues,
            metadata: new QueuePauseMetadata(app(CacheFactory::class)),
            capabilities: new FrameworkCapabilities(queuePausing: false, timedQueuePausing: false),
        ))->for('redis', 'reports');

        expect($state->toArray())->toBe([
            'paused' => false,
            'pausedUntil' => null,
        ]);
    });

    it('reports a running queue and clears stale deadline metadata', function (): void {
        requireQueuePausing();

        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $metadata->storeUntil('redis', 'reports', CarbonImmutable::parse('2026-07-20 19:00:00 UTC'));
        $queues = app(QueueManager::class);
        $queues->resume('redis', 'reports');

        $state = (new QueuePauseStatus($queues, $metadata))->for('redis', 'reports');

        expect($state->toArray())->toBe([
            'paused' => false,
            'pausedUntil' => null,
        ])->and($metadata->pausedUntil('redis', 'reports'))->toBeNull();
    });

    it('reports a timed pause with its readable deadline', function (): void {
        requireTimedQueuePausing();

        $deadline = CarbonImmutable::parse('2026-07-20 19:00:00 UTC');
        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $metadata->storeUntil('redis', 'reports', $deadline);
        $queues = app(QueueManager::class);
        $queues->pauseFor('redis', 'reports', $deadline);

        $state = (new QueuePauseStatus($queues, $metadata))->for('redis', 'reports');

        expect($state->toArray())->toBe([
            'paused' => true,
            'pausedUntil' => $deadline->timestamp,
        ]);
    });

    it('reports an externally managed pause without inventing a deadline', function (): void {
        requireQueuePausing();

        $queues = app(QueueManager::class);
        $queues->pause('redis', 'reports');

        $state = (new QueuePauseStatus(
            $queues,
            new QueuePauseMetadata(app(CacheFactory::class)),
        ))->for('redis', 'reports');

        expect($state->toArray())->toBe([
            'paused' => true,
            'pausedUntil' => null,
        ]);
    });

    it('does not report a global pause when the framework cannot pause all queues', function (): void {
        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        app(CacheFactory::class)->store()->forever('illuminate:queues:paused', true);

        $status = new QueuePauseStatus(
            queues: mockDashboardContract(QueueManager::class),
            metadata: $metadata,
            capabilities: new FrameworkCapabilities(queuePausing: true, queuePausingAll: false),
        );

        expect($status->allPaused())->toBeFalse();
    });

    it('reports Laravel global pause independently of a single queue pause', function (): void {
        requireQueuePausingAll();

        $metadata = new QueuePauseMetadata(app(CacheFactory::class));
        $queues = app(QueueManager::class);
        $status = new QueuePauseStatus($queues, $metadata);

        $queues->pause('redis', 'reports');

        expect($status->allPaused())->toBeFalse();

        $queues->pauseAll();

        expect($status->allPaused())->toBeTrue()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue();

        $queues->resumeAll();

        expect($status->allPaused())->toBeFalse()
            ->and($queues->isPaused('redis', 'reports'))->toBeTrue();
    });
});
