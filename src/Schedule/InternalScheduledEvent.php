<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

/**
 * Scheduler events Zenith registers for its own maintenance. The Schedule page
 * hides them because they are not part of the application's schedule.
 */
enum InternalScheduledEvent: string
{
    case DynamicCrons = 'zenith:dynamic-crons';
    case ChunkFlush = 'zenith:chunk-flush';
    case RelayOutbox = 'zenith:relay-outbox';
}
