<?php

declare(strict_types=1);

use Illuminate\Queue\Worker;
use NckRtl\HorizonNewDawn\Support\FrameworkCapabilities;

describe('FrameworkCapabilities', function (): void {
    it('detects basic and timed queue pausing independently of package metadata', function (): void {
        $capabilities = FrameworkCapabilities::detect();

        expect($capabilities->queuePausing)->toBe(queuePausingIsSupported())
            ->and($capabilities->timedQueuePausing)->toBe(timedQueuePausingIsSupported());

        if ($capabilities->timedQueuePausing) {
            expect($capabilities->queuePausing)->toBeTrue();
        }
    });

    it('disables both pause capabilities when worker pause polling is inactive', function (): void {
        $reflection = new ReflectionClass(Worker::class);

        if (! $reflection->hasProperty('pausable')) {
            // Older Laravel releases have no separate worker pause-polling flag.
            $capabilities = FrameworkCapabilities::detect();

            expect($capabilities->queuePausing)->toBe(queuePausingIsSupported())
                ->and($capabilities->timedQueuePausing)->toBe(timedQueuePausingIsSupported());

            return;
        }

        $property = $reflection->getProperty('pausable');
        $original = $property->getValue();
        $property->setValue(null, false);

        try {
            $capabilities = FrameworkCapabilities::detect();

            expect($capabilities->queuePausing)->toBeFalse()
                ->and($capabilities->timedQueuePausing)->toBeFalse();
        } finally {
            $property->setValue(null, $original);
        }
    });

    it('serializes both pause capabilities for the interface shell', function (): void {
        $capabilities = new FrameworkCapabilities(queuePausing: true, timedQueuePausing: false);

        expect($capabilities->toArray())->toBe([
            'queuePausing' => true,
            'timedQueuePausing' => false,
        ]);
    });
});
