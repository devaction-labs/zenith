<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Relay;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RunRelayedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $relayId,
        public object $target,
    ) {}

    public function handle(Container $container): void
    {
        $result = method_exists($this->target, 'handle')
            ? $container->call([$this->target, 'handle'])
            : null;

        Relay::record($this->relayId, $result);
    }

    /**
     * Record the failure once the job has failed for good, so awaiting callers keep
     * waiting while it still has attempts left.
     */
    public function failed(Throwable $exception): void
    {
        Relay::fail($this->relayId, $exception->getMessage(), $exception::class);
    }
}
