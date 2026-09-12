<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Queues\Data\QueueRowData;
use DevactionLabs\Zenith\Queues\QueuesData;
use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\MetricsRepository;

final class ExportMetricsCommand extends Command
{
    protected $signature = 'zenith:export-metrics';

    protected $description = 'Print queue depth, wait time, and throughput as Prometheus text-format samples';

    public function handle(QueuesData $queues, MetricsRepository $metrics): int
    {
        $list = $queues->all();

        if (! $list->available) {
            $this->components->error('Horizon queues are currently unavailable.');

            return self::FAILURE;
        }

        $this->printGauge(
            'zenith_queue_depth',
            'Pending jobs (ready + reserved + delayed) in the queue.',
            $list->queues,
            fn (QueueRowData $queue): int => $queue->ready + $queue->reserved + $queue->delayed,
        );

        $this->printGauge(
            'zenith_queue_wait_seconds',
            "Estimated seconds to clear the queue's current backlog.",
            $list->queues,
            fn (QueueRowData $queue): float => $queue->wait,
        );

        $this->printGauge(
            'zenith_queue_throughput_per_minute',
            'Jobs processed per minute for the queue.',
            $list->queues,
            fn (QueueRowData $queue): float => (float) $metrics->throughputForQueue($queue->name),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, QueueRowData>  $queues
     * @param  callable(QueueRowData): (int|float)  $value
     */
    private function printGauge(string $name, string $help, array $queues, callable $value): void
    {
        $this->line("# HELP {$name} {$help}");
        $this->line("# TYPE {$name} gauge");

        foreach ($queues as $queue) {
            $sample = $value($queue);
            $this->line(sprintf('%s{queue="%s"} %s', $name, $queue->name, $sample));
        }
    }
}
