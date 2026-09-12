<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Metrics;

use DevactionLabs\Zenith\Metrics\Data\MetricPreviewData;
use DevactionLabs\Zenith\Metrics\Data\MetricRowData;
use DevactionLabs\Zenith\Metrics\Data\MetricSnapshotData;
use DevactionLabs\Zenith\Metrics\Data\MetricsPageData;
use Laravel\Horizon\Contracts\MetricsRepository;
use Throwable;

final readonly class MetricsData
{
    public function __construct(private MetricsRepository $metrics) {}

    public function index(MetricType $type): MetricsPageData
    {
        try {
            $names = match ($type) {
                MetricType::Jobs => $this->metrics->measuredJobs(),
                MetricType::Queues => $this->metrics->measuredQueues(),
            };
            $metrics = [];

            foreach (array_unique(array_filter($names, is_string(...))) as $name) {
                if ($name === '') {
                    continue;
                }

                $metrics[] = new MetricRowData(
                    name: $name,
                    throughput: $this->throughput($type, $name),
                    runtime: $this->runtime($type, $name),
                );
            }

            usort(
                $metrics,
                static fn (MetricRowData $left, MetricRowData $right): int => strnatcasecmp($left->name, $right->name),
            );

            return new MetricsPageData(
                available: true,
                metrics: $metrics,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new MetricsPageData(
                available: false,
                metrics: [],
                message: "{$type->singular()} metrics are currently unavailable.",
            );
        }
    }

    public function preview(MetricType $type, string $name): MetricPreviewData
    {
        try {
            $rawSnapshots = match ($type) {
                MetricType::Jobs => $this->metrics->snapshotsForJob($name),
                MetricType::Queues => $this->metrics->snapshotsForQueue($name),
            };
            /** @var list<MetricSnapshotData> $snapshots */
            $snapshots = [];

            foreach ($rawSnapshots as $rawSnapshot) {
                $snapshot = $this->snapshot($rawSnapshot);

                if ($snapshot === null) {
                    continue;
                }

                $snapshots[] = $snapshot;
            }

            usort(
                $snapshots,
                static fn (MetricSnapshotData $left, MetricSnapshotData $right): int => $left->timestamp <=> $right->timestamp,
            );

            return new MetricPreviewData(
                available: true,
                name: $name,
                snapshots: $snapshots,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new MetricPreviewData(
                available: false,
                name: $name,
                snapshots: [],
                message: "Metrics for this {$type->singular()} are currently unavailable.",
            );
        }
    }

    private function throughput(MetricType $type, string $name): int
    {
        return match ($type) {
            MetricType::Jobs => $this->metrics->throughputForJob($name),
            MetricType::Queues => $this->metrics->throughputForQueue($name),
        };
    }

    private function runtime(MetricType $type, string $name): float
    {
        $runtime = match ($type) {
            MetricType::Jobs => $this->metrics->runtimeForJob($name),
            MetricType::Queues => $this->metrics->runtimeForQueue($name),
        };

        return round($runtime / 1000, 3);
    }

    private function snapshot(mixed $snapshot): ?MetricSnapshotData
    {
        if (is_object($snapshot)) {
            $snapshot = (array) $snapshot;
        }

        if (! is_array($snapshot)) {
            return null;
        }

        $timestamp = $snapshot['time'] ?? null;
        $throughput = $snapshot['throughput'] ?? null;
        $runtime = $snapshot['runtime'] ?? null;

        if (
            ! is_numeric($timestamp)
            || ($throughput !== null && ! is_numeric($throughput))
            || ($runtime !== null && ! is_numeric($runtime))
        ) {
            return null;
        }

        return new MetricSnapshotData(
            timestamp: (int) $timestamp,
            throughput: $throughput === null ? 0 : (int) $throughput,
            runtime: $runtime === null ? null : round((float) $runtime / 1000, 3),
        );
    }
}
