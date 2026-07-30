<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Support;

use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use LogicException;
use ReflectionClass;
use Spatie\LaravelData\Data;

final class FrameworkCapabilities extends Data
{
    public function __construct(
        public bool $queuePausing,
        public bool $timedQueuePausing = false,
    ) {}

    public static function detect(): self
    {
        $queueManager = new ReflectionClass(QueueManager::class);

        $queuePausing = $queueManager->hasMethod('pause')
            && $queueManager->hasMethod('resume')
            && $queueManager->hasMethod('isPaused')
            && self::workerPausePollingEnabled();

        return new self(
            queuePausing: $queuePausing,
            timedQueuePausing: $queuePausing && $queueManager->hasMethod('pauseFor'),
        );
    }

    public function ensureQueuePausing(): void
    {
        if (! $this->queuePausing) {
            throw new LogicException('Queue pausing is not supported by the installed Laravel version.');
        }
    }

    private static function workerPausePollingEnabled(): bool
    {
        if (! property_exists(Worker::class, 'pausable')) {
            return true;
        }

        return Worker::$pausable;
    }
}
