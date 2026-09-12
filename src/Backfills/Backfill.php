<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Backfills;

use Closure;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

final readonly class Backfill
{
    /**
     * @param  (Closure(?int): array{cursor: int, done: bool})|class-string  $page
     */
    private function __construct(
        private Closure|string $page,
    ) {}

    /**
     * Create a backfill from an invokable page class or a page closure.
     *
     * An invokable class runs every page as a queued RunBackfillPage job that
     * queues the next page with the returned cursor, so a failed page can be
     * retried on its own. A closure runs every page in the calling process,
     * because its captured state cannot cross a queue boundary.
     *
     * @param  (Closure(?int): array{cursor: int, done: bool})|class-string  $page
     *
     * @throws InvalidArgumentException
     */
    public static function make(Closure|string $page): self
    {
        if (is_string($page) && ! method_exists($page, '__invoke')) {
            throw new InvalidArgumentException("Backfill page [{$page}] must be an invokable class.");
        }

        return new self($page);
    }

    public function dispatch(?int $cursor = null): void
    {
        if (is_string($this->page)) {
            Bus::dispatch(new RunBackfillPage($this->page, $cursor));

            return;
        }

        $from = $cursor;

        do {
            $result = ($this->page)($from);

            if ($result['done'] || $result['cursor'] === $from) {
                return;
            }

            $from = $result['cursor'];
        } while (true);
    }
}
