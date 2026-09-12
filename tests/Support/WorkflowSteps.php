<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\Signal;
use DevactionLabs\Zenith\Signals\SignalWaiting;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;

function dispatchedWorkflowStep(string $stepName): RunWorkflowStep
{
    $job = Bus::dispatched(
        RunWorkflowStep::class,
        static fn (RunWorkflowStep $job): bool => $job->stepName === $stepName,
    )->last();

    if (! $job instanceof RunWorkflowStep) {
        throw new RuntimeException("No job was dispatched for workflow step [{$stepName}].");
    }

    return $job;
}

final class FetchWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(array $payload, array $context): array
    {
        return ['items' => $payload['seed'] ?? [1, 2]];
    }
}

final class ProcessWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, int>
     */
    public function handle(array $payload, array $context): array
    {
        $items = $payload['items'] ?? [];

        return ['count' => is_countable($items) ? count($items) : 0];
    }
}

final class FailingWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function handle(array $payload, array $context): never
    {
        throw new RuntimeException('step failed');
    }
}

#[Tries(3)]
#[Backoff(5, 10)]
#[Timeout(30)]
#[FailOnTimeout]
#[MaxExceptions(2)]
#[Queue('workflows')]
#[Connection('database')]
final class ConfiguredWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    public function handle(array $payload, array $context): array
    {
        return ['configured' => true];
    }
}

#[Tries(3)]
final class FlakyWorkflowStep implements ShouldQueue
{
    use Queueable;

    public static int $failuresLeft = 0;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    public function handle(array $payload, array $context): array
    {
        if (self::$failuresLeft > 0) {
            self::$failuresLeft--;

            throw new RuntimeException('flaky failure');
        }

        return ['recovered' => true];
    }
}

#[Tries(2)]
final class ExhaustingWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function handle(array $payload, array $context): never
    {
        throw new RuntimeException('still failing');
    }
}

final class WaitingWorkflowStep implements ShouldQueue
{
    use Queueable;

    public static bool $signalled = false;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    public function handle(array $payload, array $context): array
    {
        if (! self::$signalled) {
            throw new SignalWaiting('Waiting for the approval signal.');
        }

        return ['approved' => true];
    }
}

final class PatientWorkflowStep implements ShouldQueue
{
    use Queueable;

    public function retryUntil(): DateTimeInterface
    {
        return Date::now()->addMinutes(5);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    public function handle(array $payload, array $context): array
    {
        return ['patient' => true];
    }
}

final class ReleasingWaitWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * Mirrors Signal::await, which releases the bound queue job before it reports waiting.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function handle(array $payload, array $context): never
    {
        app(Job::class)->release(5);

        throw new SignalWaiting('Waiting for the approval signal.');
    }
}

final class CountingWorkflowStep implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $runs = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function handle(array $payload, array $context): array
    {
        $label = is_string($payload['label'] ?? null) ? $payload['label'] : 'step';

        self::$runs[] = $label;

        return ['ran' => $label];
    }
}

final class CompensateWorkflowStep implements ShouldQueue
{
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $released = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $context
     */
    public function handle(array $payload, array $output, array $context): void
    {
        self::$released[] = $output;
    }
}

final class AwaitingSignalWorkflowStep implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(array $payload, array $context): array
    {
        return Signal::await('workflow-approval', seconds: 30, retryAfter: 5);
    }
}
