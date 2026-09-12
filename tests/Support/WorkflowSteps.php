<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        return ['count' => count($items)];
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
