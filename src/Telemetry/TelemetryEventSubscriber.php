<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\Data\JobAttemptData;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns Laravel's queue events into recorded telemetry attempts.
 *
 * Bound as a singleton (see `ZenithServiceProvider`) so the same instance
 * observes both ends of an attempt within one worker process: `JobProcessing`
 * opens an in-memory timer keyed by job UUID, and the attempt's terminal
 * event closes it to compute runtime. The same events also open and close
 * that job's `InFlightJobTracker` entry, so the "executing now" view and the
 * counters/histograms share one lifecycle observation.
 *
 * Only three events are observed. `Illuminate\Queue\Worker::process()`
 * always dispatches exactly one `JobAttempted` in its `finally` block, and
 * by then the job object's `hasFailed()` / `isReleased()` state is final;
 * listening to the more granular `JobProcessed`, `JobFailed`, `JobReleased`,
 * `JobReleasedAfterException`, and `JobExceptionOccurred` events instead
 * would risk double counting (`JobProcessed` and `JobReleased` both fire for
 * a job that calls `$this->release()` without throwing) or under-counting
 * (a job that calls `$this->fail()` manually still gets a `JobProcessed`).
 * `JobTimedOut` is the one path that never reaches `JobAttempted`, because
 * the worker's SIGALRM handler kills the process immediately after
 * dispatching it.
 *
 * `JobInterrupted` (dispatched when a worker receives a stop signal while an
 * `Interruptible` job is running) is deliberately not observed here: it only
 * asks the job to wind down, it does not end the attempt, so clearing the
 * in-flight entry on it would show a still-running job as finished.
 *
 * Every terminal event also appends one entry to that job's attempt
 * history via `AttemptHistory`, whether or not an exception occurred: a job
 * that fails twice and then succeeds should show three attempts, the last
 * one without exception details.
 */
final class TelemetryEventSubscriber
{
    private const int MAXIMUM_MESSAGE_LENGTH = 500;

    /** @var array<string, array{startedAt: float, waitMilliseconds: int|null}> */
    private array $pending = [];

    public function __construct(
        private readonly TelemetryRecorder $recorder,
        private readonly InFlightJobTracker $inFlight,
        private readonly AttemptHistory $attempts,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class => 'handleProcessing',
            JobAttempted::class => 'handleAttempted',
            JobTimedOut::class => 'handleTimedOut',
        ];
    }

    public function handleProcessing(JobProcessing $event): void
    {
        $uuid = $event->job->uuid();

        if ($uuid === null) {
            return;
        }

        $this->pending[$uuid] = [
            'startedAt' => microtime(true),
            'waitMilliseconds' => $this->waitMilliseconds($event->job),
        ];

        $this->inFlight->start(
            jobId: $uuid,
            job: JobIdentity::fromJob($event->job),
            worker: WorkerIdentity::current(),
            timeoutSeconds: $event->job->timeout(),
        );
    }

    public function handleAttempted(JobAttempted $event): void
    {
        $this->recordTerminal($event->job, $this->outcomeFor($event->job), $event->exception);
    }

    public function handleTimedOut(JobTimedOut $event): void
    {
        $this->recordTerminal($event->job, TelemetryOutcome::TimedOut);
    }

    private function recordTerminal(JobContract $job, TelemetryOutcome $outcome, ?Throwable $exception = null): void
    {
        $timing = $this->popTiming($job);
        $runtimeMilliseconds = $timing !== null ? $this->elapsedMilliseconds($timing['startedAt']) : null;
        $worker = WorkerIdentity::current();

        $this->recorder->record(
            outcome: $outcome,
            job: JobIdentity::fromJob($job),
            worker: $worker,
            runtimeMilliseconds: $runtimeMilliseconds,
            waitMilliseconds: $timing['waitMilliseconds'] ?? null,
        );

        $uuid = $job->uuid();

        if ($uuid === null) {
            return;
        }

        $this->inFlight->finish($uuid);
        $this->attempts->record($uuid, new JobAttemptData(
            attempt: $job->attempts(),
            outcome: $outcome->value,
            exceptionClass: $exception !== null ? $exception::class : null,
            message: $exception !== null ? Str::limit($exception->getMessage(), self::MAXIMUM_MESSAGE_LENGTH) : null,
            fingerprint: $exception !== null ? AttemptFingerprint::for($exception) : null,
            runtimeMilliseconds: $runtimeMilliseconds,
            node: $worker->node,
            occurredAt: CarbonImmutable::now()->getTimestamp(),
        ));
    }

    /** @return array{startedAt: float, waitMilliseconds: int|null}|null */
    private function popTiming(JobContract $job): ?array
    {
        $uuid = $job->uuid();

        if ($uuid === null || ! isset($this->pending[$uuid])) {
            return null;
        }

        $timing = $this->pending[$uuid];
        unset($this->pending[$uuid]);

        return $timing;
    }

    private function outcomeFor(JobContract $job): TelemetryOutcome
    {
        return match (true) {
            $job->hasFailed() => TelemetryOutcome::Failed,
            $job->isReleased() => TelemetryOutcome::Released,
            default => TelemetryOutcome::Processed,
        };
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function waitMilliseconds(JobContract $job): ?int
    {
        $createdAt = $job->payload()['createdAt'] ?? null;

        if (! is_numeric($createdAt)) {
            return null;
        }

        $waitSeconds = microtime(true) - (float) $createdAt;

        return (int) round(max(0.0, $waitSeconds) * 1000);
    }
}
