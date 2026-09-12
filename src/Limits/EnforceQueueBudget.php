<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Closure;
use Illuminate\Contracts\Queue\Job;

final readonly class EnforceQueueBudget
{
    public function __construct(
        private QueueBudget $budget,
        private string $name,
        private int $allowed,
        private int $period,
        private int $weight = 1,
        private ?string $partition = null,
    ) {}

    /**
     * @param  Closure(): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! $this->budget->consume($this->name, $this->allowed, $this->period, $this->weight, $this->partition)) {
            if (method_exists($job, 'release')) {
                $job->release($this->period);

                return null;
            }

            if ($job instanceof Job) {
                $job->release($this->period);

                return null;
            }
        }

        return $next();
    }
}
