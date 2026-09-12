<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class JobHistoryRecorder
{
    public const string TABLE = 'zenith_job_history';

    private const int ERROR_SUMMARY_LENGTH = 500;

    private const int FIELD_LENGTH = 255;

    public function __construct(
        private JobHistoryStopwatch $stopwatch,
    ) {}

    public function handleProcessing(JobProcessing $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->stopwatch->start($this->timerKey($event->connectionName, $event->job));
    }

    public function handleProcessed(JobProcessed $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $payload = $this->payload($event->job);

        $this->store(
            connectionName: $event->connectionName,
            job: $event->job,
            status: $this->silenced($payload) ? JobHistoryStatus::Silenced : JobHistoryStatus::Completed,
            payload: $payload,
            exception: null,
        );
    }

    public function handleFailed(JobFailed $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->store(
            connectionName: $event->connectionName,
            job: $event->job,
            status: JobHistoryStatus::Failed,
            payload: $this->payload($event->job),
            exception: $event->exception,
        );
    }

    private function enabled(): bool
    {
        return config('zenith.history.enabled') === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(
        string $connectionName,
        Job $job,
        JobHistoryStatus $status,
        array $payload,
        ?Throwable $exception,
    ): void {
        $runtimeMs = $this->stopwatch->checkAndForget($this->timerKey($connectionName, $job));

        if (! $this->tableReady()) {
            return;
        }

        try {
            JobHistory::query()->create([
                'job_class' => $this->truncate($job->resolveName(), self::FIELD_LENGTH),
                'queue' => $this->truncate($this->queueName($job), self::FIELD_LENGTH),
                'connection' => $this->truncate($connectionName, self::FIELD_LENGTH),
                'status' => $status,
                'attempts' => $job->attempts(),
                'runtime_ms' => $runtimeMs,
                'tags' => $this->tags($payload),
                'error' => $exception === null ? null : $this->errorSummary($exception),
                'pushed_at' => $this->pushedAt($payload),
                'completed_at' => $status === JobHistoryStatus::Failed ? null : now(),
                'failed_at' => $status === JobHistoryStatus::Failed ? now() : null,
            ]);
        } catch (Throwable $reportable) {
            report($reportable);
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    private function timerKey(string $connectionName, Job $job): string
    {
        return "{$connectionName}:{$job->getJobId()}";
    }

    private function queueName(Job $job): string
    {
        $queue = $job->getQueue();

        return $queue === '' ? 'default' : $queue;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Job $job): array
    {
        $filtered = [];

        foreach ($job->payload() as $key => $value) {
            if (is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function tags(array $payload): array
    {
        $tags = $payload['tags'] ?? null;

        return is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function silenced(array $payload): bool
    {
        return ($payload['silenced'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushedAt(array $payload): ?Carbon
    {
        $createdAt = $payload['createdAt'] ?? null;

        return is_int($createdAt) ? Carbon::createFromTimestamp($createdAt) : null;
    }

    private function errorSummary(Throwable $exception): string
    {
        return $this->truncate(
            sprintf('%s: %s', $exception::class, $exception->getMessage()),
            self::ERROR_SUMMARY_LENGTH,
        );
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
