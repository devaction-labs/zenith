<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\Signal;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

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
