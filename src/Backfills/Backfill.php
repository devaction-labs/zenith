<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Backfills;

use Closure;

final readonly class Backfill
{
    /**
     * @param  Closure(?int): array{cursor: int, done: bool}  $page
     */
    private function __construct(
        private Closure $page,
    ) {}

    /**
     * @param  Closure(?int): array{cursor: int, done: bool}  $page
     */
    public static function make(Closure $page): self
    {
        return new self($page);
    }

    public function dispatch(?int $cursor = null): void
    {
        $from = $cursor;

        do {
            $result = ($this->page)($from);

            if ($result['done'] === true) {
                return;
            }

            $next = $result['cursor'];

            if ($next === $from) {
                return;
            }

            $from = $next;
        } while (true);
    }
}
