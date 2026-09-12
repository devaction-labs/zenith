<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

final class JobHistoryStopwatch
{
    /** @var array<string, float> */
    private array $timers = [];

    public function start(string $key): void
    {
        $this->timers[$key] = microtime(true);
    }

    public function checkAndForget(string $key): ?int
    {
        $startedAt = $this->timers[$key] ?? null;

        unset($this->timers[$key]);

        if ($startedAt === null) {
            return null;
        }

        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
