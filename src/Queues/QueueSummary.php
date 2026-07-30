<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Queues;

use Laravel\Horizon\Contracts\MetricsRepository;
use NckRtl\HorizonNewDawn\Metrics\SnapshotJobsPerMinute;
use NckRtl\HorizonNewDawn\Queues\Data\QueueRowData;
use NckRtl\HorizonNewDawn\Queues\Data\QueueSummaryData;
use Throwable;

final readonly class QueueSummary
{
    public function __construct(
        private QueueJobsData $jobs,
        private QueueBatchesData $batches,
        private MetricsRepository $metrics,
        private SnapshotJobsPerMinute $snapshotJobsPerMinute,
    ) {}

    public function forQueue(QueueRowData $queue): QueueSummaryData
    {
        $jobs = $this->jobs->summary($queue->name);
        $batches = $this->batches->summary($queue->name);
        [$jobsPerMinute, $throughput, $averageRuntime] = $this->snapshotMetrics($queue->name);

        return new QueueSummaryData(
            available: true,
            name: $queue->name,
            connections: $queue->connections,
            pauseTargets: $queue->pauseTargets,
            pendingJobs: $jobs->warming ? null : $jobs->pending,
            pendingComplete: ! $jobs->warming && $jobs->pendingComplete,
            retainedJobsWarming: $jobs->warming,
            pendingReserved: $queue->reserved,
            pendingReadyNow: $queue->ready,
            pendingDelayed: $queue->delayed,
            failedJobs: $jobs->warming ? null : $jobs->failed,
            failedComplete: ! $jobs->warming && $jobs->failedComplete,
            failedJobsPerMinute: $jobs->warming ? null : $jobs->failedPerMinute,
            failedJobsPerMinuteComplete: ! $jobs->warming && $jobs->failedPerMinuteComplete,
            failedJobsPastHour: $jobs->warming ? null : $jobs->failedPastHour,
            failedJobsPastHourComplete: ! $jobs->warming && $jobs->failedPastHourComplete,
            failedJobsPastDay: $jobs->warming ? null : $jobs->failedPastDay,
            failedJobsPastDayComplete: ! $jobs->warming && $jobs->failedPastDayComplete,
            failedRetentionMinutes: $jobs->failedRetentionMinutes,
            completedJobs: $jobs->warming ? null : $jobs->completed,
            completedAvailable: ! $jobs->warming && $jobs->completedAvailable,
            completedComplete: ! $jobs->warming && $jobs->completedComplete,
            completedJobsPerMinute: $jobs->warming ? null : $jobs->completedPerMinute,
            completedJobsPerMinuteComplete: ! $jobs->warming && $jobs->completedPerMinuteComplete,
            completedJobsPastHour: $jobs->warming ? null : $jobs->completedPastHour,
            completedJobsPastHourComplete: ! $jobs->warming && $jobs->completedPastHourComplete,
            completedJobsPastDay: $jobs->warming ? null : $jobs->completedPastDay,
            completedJobsPastDayComplete: ! $jobs->warming && $jobs->completedPastDayComplete,
            completedRetentionMinutes: $jobs->completedRetentionMinutes,
            silencedJobs: $jobs->warming ? null : $jobs->silenced,
            silencedComplete: ! $jobs->warming && $jobs->silencedComplete,
            batches: $batches->total,
            activeBatches: $batches->active,
            batchesComplete: $batches->complete,
            batchPreviews: $batches->previews,
            processes: $queue->processes,
            waitThreshold: $queue->waitThreshold,
            jobsPerMinute: $jobsPerMinute,
            throughput: $throughput,
            averageRuntime: $averageRuntime,
            message: $this->partialDataMessage($jobs->message, $batches->message),
        );
    }

    public static function unavailable(string $queue, string $message): QueueSummaryData
    {
        return new QueueSummaryData(
            available: false,
            name: $queue,
            connections: [],
            pauseTargets: [],
            pendingJobs: null,
            pendingComplete: false,
            retainedJobsWarming: false,
            pendingReserved: null,
            pendingReadyNow: null,
            pendingDelayed: null,
            failedJobs: null,
            failedComplete: false,
            failedJobsPerMinute: null,
            failedJobsPerMinuteComplete: false,
            failedJobsPastHour: null,
            failedJobsPastHourComplete: false,
            failedJobsPastDay: null,
            failedJobsPastDayComplete: false,
            failedRetentionMinutes: max(0, (int) config('horizon.trim.failed', 10080)),
            completedJobs: null,
            completedAvailable: false,
            completedComplete: false,
            completedJobsPerMinute: null,
            completedJobsPerMinuteComplete: false,
            completedJobsPastHour: null,
            completedJobsPastHourComplete: false,
            completedJobsPastDay: null,
            completedJobsPastDayComplete: false,
            completedRetentionMinutes: max(0, (int) config('horizon.trim.completed', 60)),
            silencedJobs: null,
            silencedComplete: false,
            batches: null,
            activeBatches: null,
            batchesComplete: false,
            batchPreviews: [],
            processes: null,
            waitThreshold: null,
            jobsPerMinute: null,
            throughput: null,
            averageRuntime: null,
            message: $message,
        );
    }

    /** @return array{0: int|float|null, 1: ?int, 2: ?float} */
    private function snapshotMetrics(string $queue): array
    {
        try {
            $throughput = $this->metrics->throughputForQueue($queue);
            $averageRuntime = $throughput > 0
                ? round($this->metrics->runtimeForQueue($queue) / 1000, 3)
                : null;
            $jobsPerMinute = $this->snapshotJobsPerMinute->project($throughput);

            return [$jobsPerMinute, $throughput, $averageRuntime];
        } catch (Throwable $exception) {
            report($exception);

            return [null, null, null];
        }
    }

    private function partialDataMessage(?string ...$messages): ?string
    {
        $messages = array_values(array_unique(array_filter($messages)));

        return $messages === [] ? null : implode(' ', $messages);
    }
}
