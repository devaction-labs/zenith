<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\Data\RunningJobData;
use DevactionLabs\Zenith\Telemetry\Data\RunningJobsNodeSummaryData;
use DevactionLabs\Zenith\Telemetry\Data\RunningJobsPageData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Reads the in-flight index `InFlightJobTracker` maintains into a page of
 * currently executing jobs, normalized for the frontend.
 */
final readonly class InFlightJobs
{
    public function __construct(private RedisFactory $redis) {}

    public function list(): RunningJobsPageData
    {
        if (! TelemetryRegistration::enabled()) {
            return new RunningJobsPageData(
                available: false,
                jobs: [],
                nodeSummary: [],
                message: 'Enable the telemetry recorder (zenith.telemetry.enabled) to see jobs executing right now.',
            );
        }

        try {
            $jobs = $this->hydrateAll();

            usort(
                $jobs,
                static fn (RunningJobData $left, RunningJobData $right): int => $right->elapsedSeconds <=> $left->elapsedSeconds,
            );

            return new RunningJobsPageData(
                available: true,
                jobs: $jobs,
                nodeSummary: $this->nodeSummary($jobs),
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new RunningJobsPageData(
                available: false,
                jobs: [],
                nodeSummary: [],
                message: 'Executing jobs are currently unavailable.',
            );
        }
    }

    /** @return list<RunningJobData> */
    private function hydrateAll(): array
    {
        $connection = $this->redis->connection('horizon');
        $ids = $connection->zrange(TelemetryKeys::inFlightIndex(), 0, -1);
        $now = CarbonImmutable::now()->getTimestamp();
        $jobs = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            $job = $this->hydrate($connection, $id, $now);

            if ($job === null) {
                $connection->zrem(TelemetryKeys::inFlightIndex(), $id);

                continue;
            }

            $jobs[] = $job;
        }

        return $jobs;
    }

    private function hydrate(Connection $connection, string $id, int $now): ?RunningJobData
    {
        $fields = $connection->hgetall(TelemetryKeys::inFlightJob($id));

        if (! is_array($fields) || $fields === []) {
            return null;
        }

        $startedAt = $fields['startedAt'] ?? null;

        if (! is_numeric($startedAt)) {
            return null;
        }

        $timeoutSeconds = $fields['timeoutSeconds'] ?? null;
        $timeout = is_numeric($timeoutSeconds) ? (int) $timeoutSeconds : null;
        $elapsed = max(0, $now - (int) $startedAt);
        $supervisor = $this->stringField($fields, 'supervisor');

        return new RunningJobData(
            id: $id,
            queue: $this->stringField($fields, 'queue'),
            jobClass: $this->stringField($fields, 'class'),
            node: $this->stringField($fields, 'node'),
            supervisor: $supervisor !== '' ? $supervisor : null,
            startedAt: (int) $startedAt,
            elapsedSeconds: $elapsed,
            timeoutSeconds: $timeout,
            overrunning: $timeout !== null && $elapsed > $timeout,
        );
    }

    /** @param array<array-key, mixed> $fields */
    private function stringField(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  list<RunningJobData>  $jobs
     * @return list<RunningJobsNodeSummaryData>
     */
    private function nodeSummary(array $jobs): array
    {
        $counts = [];

        foreach ($jobs as $job) {
            $counts[$job->node] = ($counts[$job->node] ?? 0) + 1;
        }

        ksort($counts, SORT_STRING);

        $summary = [];

        foreach ($counts as $node => $count) {
            $summary[] = new RunningJobsNodeSummaryData($node, $count);
        }

        return $summary;
    }
}
