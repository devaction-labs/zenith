<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Backfills;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

final class RunBackfillPage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  class-string  $page
     */
    public function __construct(
        public string $page,
        public ?int $cursor = null,
        public int $completedPages = 0,
    ) {}

    /**
     * Run one page, then queue the next page on the same connection and queue
     * until the page reports it is done or stops advancing the cursor.
     *
     * @throws UnexpectedValueException
     */
    public function handle(Container $container, Dispatcher $bus): void
    {
        $page = $container->make($this->page);

        if (! is_callable($page)) {
            throw new UnexpectedValueException("Backfill page [{$this->page}] is not invokable.");
        }

        $result = $this->result($page($this->cursor));
        $completedPages = $this->completedPages + 1;

        if ($result['done'] || $result['cursor'] === $this->cursor) {
            Log::info('Zenith backfill completed.', [
                'page' => $this->page,
                'pages' => $completedPages,
                'cursor' => $result['cursor'],
            ]);

            return;
        }

        $bus->dispatch(
            (new self($this->page, $result['cursor'], $completedPages))
                ->onConnection($this->connection)
                ->onQueue($this->queue),
        );
    }

    /**
     * @return array{cursor: int, done: bool}
     *
     * @throws UnexpectedValueException
     */
    private function result(mixed $result): array
    {
        $cursor = is_array($result) ? ($result['cursor'] ?? null) : null;
        $done = is_array($result) ? ($result['done'] ?? null) : null;

        if (! is_int($cursor) || ! is_bool($done)) {
            throw new UnexpectedValueException(
                "Backfill page [{$this->page}] must return an array with an integer cursor and a boolean done flag.",
            );
        }

        return ['cursor' => $cursor, 'done' => $done];
    }
}
