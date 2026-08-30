<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Audit;

use DevactionLabs\HorizonNewDawn\Audit\Data\HorizonAuditEventData;
use DevactionLabs\HorizonNewDawn\Audit\Data\HorizonAuditPageData;
use Throwable;

final readonly class HorizonAuditLog
{
    public function __construct(
        private HorizonAuditRecorder $recorder,
    ) {}

    public function page(?int $beforeId = null, int $limit = 50): HorizonAuditPageData
    {
        if (! $this->recorder->tableReady()) {
            return new HorizonAuditPageData(
                available: false,
                events: [],
                next: null,
                message: 'Run the package migrations to store mutation audit events.',
            );
        }

        try {
            $query = HorizonAuditEvent::query()->orderByDesc('id');

            if ($beforeId !== null && $beforeId > 0) {
                $query->where('id', '<', $beforeId);
            }

            $rows = $query->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $page = $rows->take($limit);
            $last = $page->last();

            return new HorizonAuditPageData(
                available: true,
                events: $page->map(
                    fn (HorizonAuditEvent $event): HorizonAuditEventData => new HorizonAuditEventData(
                        id: (int) $event->id,
                        occurredAt: $event->occurred_at?->getTimestamp(),
                        action: (string) $event->action,
                        route: (string) $event->route,
                        userId: $event->user_id,
                        ip: $event->ip,
                        context: is_array($event->context) ? $event->context : [],
                    ),
                )->all(),
                next: $hasMore && $last instanceof HorizonAuditEvent ? (int) $last->id : null,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new HorizonAuditPageData(
                available: false,
                events: [],
                next: null,
                message: 'Audit events are currently unavailable.',
            );
        }
    }
}
