<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Queues;

use Closure;
use NckRtl\HorizonNewDawn\Queues\Data\QueueActivityPageData;

final readonly class QueueActivityData
{
    public function __construct(
        private QueueJobsData $jobs,
        private QueueBatchesData $batches,
    ) {}

    public function page(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $cursor,
    ): QueueActivityPageData {
        return match ($tab) {
            QueueActivityTab::Pending,
            QueueActivityTab::Completed,
            QueueActivityTab::Failed,
            QueueActivityTab::Silenced => $this->jobs->page(
                $queue,
                $tab,
                $cursor,
            ),
            QueueActivityTab::Batches => $this->batches->page(
                $queue,
                is_string($cursor) ? $cursor : null,
            ),
        };
    }

    public function querySignature(
        string $queue,
        QueueActivityTab $tab,
    ): string {
        return $this->jobs->querySignature($queue, $tab);
    }

    /** @param  (Closure(): QueueActivityPageData)|null  $fallbackPageResolver */
    public function listRevision(
        string $queue,
        QueueActivityTab $tab,
        ?Closure $fallbackPageResolver = null,
    ): string {
        if ($tab !== QueueActivityTab::Batches) {
            return $this->jobs->listRevision(
                $queue,
                $tab,
                $fallbackPageResolver,
            );
        }

        $page = $fallbackPageResolver !== null
            ? $fallbackPageResolver()
            : $this->batches->page($queue, null);

        return json_encode([
            $page->total,
            $page->rows[0]->id ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}
