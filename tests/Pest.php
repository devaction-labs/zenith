<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use DevactionLabs\Zenith\Tests\BrowserTestCase;
use DevactionLabs\Zenith\Tests\TestCase;
use DevactionLabs\Zenith\Workflows\Workflow;
use Illuminate\Cache\CacheManager;
use PHPUnit\Framework\SkippedWithMessageException;

require_once __DIR__.'/Support/DashboardMocks.php';
require_once __DIR__.'/Support/BrowserPageFixtures.php';
require_once __DIR__.'/Support/BulkOperationSnapshotRedis.php';
require_once __DIR__.'/Support/FakeQueueJob.php';
require_once __DIR__.'/Support/HorizonBatches.php';
require_once __DIR__.'/Support/HorizonJobs.php';
require_once __DIR__.'/Support/TelemetryFakeJob.php';
require_once __DIR__.'/Support/TelemetryRedis.php';
require_once __DIR__.'/Support/WorkflowTables.php';
require_once __DIR__.'/Support/WorkflowSteps.php';
require_once __DIR__.'/Support/WorkflowDrain.php';
require_once __DIR__.'/Support/OutboxTable.php';

pest()->extend(TestCase::class)->afterEach(function (): void {
    Workflow::stopFaking();
})->in('Compatibility', 'Feature', 'Unit');
pest()->extend(BrowserTestCase::class)->in('Browser');
pest()->tia()->locally()->baselined();

function queuePausingIsSupported(): bool
{
    return FrameworkCapabilities::detect()->queuePausing;
}

function timedQueuePausingIsSupported(): bool
{
    return FrameworkCapabilities::detect()->timedQueuePausing;
}

function requireQueuePausing(): void
{
    if (! queuePausingIsSupported()) {
        throw new SkippedWithMessageException('Queue pausing is unavailable on this Laravel version.');
    }
}

function requireTimedQueuePausing(): void
{
    if (! timedQueuePausingIsSupported()) {
        throw new SkippedWithMessageException('Timed queue pausing is unavailable on this Laravel version.');
    }
}

function queuePausingAllIsSupported(): bool
{
    return FrameworkCapabilities::detect()->queuePausingAll;
}

function requireQueuePausingAll(): void
{
    if (! queuePausingAllIsSupported()) {
        throw new SkippedWithMessageException('Pausing all queues is unavailable on this Laravel version.');
    }
}

function requireConfigurableCacheUnserialization(): void
{
    if (! (new ReflectionClass(CacheManager::class))->hasMethod('getSerializableClasses')) {
        throw new SkippedWithMessageException(
            'Configurable cache unserialization is unavailable on this Laravel version.',
        );
    }
}
