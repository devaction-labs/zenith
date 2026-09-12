<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

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
        public bool $queuePausingAll = false,
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
            queuePausingAll: $queuePausing
                && $queueManager->hasMethod('pauseAll')
                && $queueManager->hasMethod('resumeAll'),
        );
    }

    public function ensureQueuePausing(): void
    {
        if (! $this->queuePausing) {
            throw new LogicException('Queue pausing is not supported by the installed Laravel version.');
        }
    }

    public function ensureQueuePausingAll(): void
    {
        if (! $this->queuePausingAll) {
            throw new LogicException('Pausing all queues is not supported by the installed Laravel version.');
        }
    }

    private static function workerPausePollingEnabled(): bool
    {
        $reflection = new ReflectionClass(Worker::class);

        // Worker::$pausable exists only on Laravel versions that separate worker
        // pause polling from queue manager pause APIs.
        if (! $reflection->hasProperty('pausable')) {
            return true;
        }

        $property = $reflection->getProperty('pausable');

        if (! $property->isStatic()) {
            return true;
        }

        /** @var mixed $pausable */
        $pausable = $property->getValue();

        return $pausable !== false;
    }
}
