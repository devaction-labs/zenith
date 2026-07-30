<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

interface PendingJobEntryScanner
{
    /**
     * Capture ready, reserved, and delayed queue structures for one target at a
     * single Redis TIME boundary and return state-tagged pending entries.
     *
     * @param  array{connection: string, queue: string}  $target
     * @return list<array{
     *     id: string,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>
     */
    public function pendingQueueEntries(array $target): array;
}
