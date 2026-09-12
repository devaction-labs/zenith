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
        try {
            $result = method_exists($this->target, 'handle')
                ? $container->call([$this->target, 'handle'])
                : null;

            Relay::record($this->relayId, $result);
        } catch (Throwable $exception) {
            Relay::fail($this->relayId, $exception->getMessage());

            throw $exception;
        }
    }
}
