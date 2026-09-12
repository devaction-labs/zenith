<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Queues\Data\QueueClassRouteData;
use DevactionLabs\Zenith\Queues\Data\QueueRoutingData;
use Illuminate\Container\Container;
use Illuminate\Queue\QueueRoutes;
use Throwable;

final class QueueRouting
{
    /**
     * @param  array<int, string>  $connections
     */
    public function forQueue(string $queue, array $connections): QueueRoutingData
    {
        try {
            $routes = $this->routes();

            if ($routes === null) {
                return QueueRoutingData::unavailable();
            }

            $classRoutes = [];

            foreach ($routes->all() as $class => $destination) {
                [$connection, $destinationQueue] = $this->normalizeDestination($destination);

                if ($destinationQueue !== $queue) {
                    continue;
                }

                $classRoutes[] = new QueueClassRouteData(
                    class: (string) $class,
                    queue: $destinationQueue,
                    connection: $connection,
                );
            }

            $forwardedQueue = null;
            $forwardedConnection = null;

            foreach ($connections as $connection) {
                if ($connection === '') {
                    continue;
                }

                $forwarded = $routes->forwardedQueue($queue, $connection);

                if ($forwarded === $queue) {
                    continue;
                }

                $forwardedQueue = $forwarded;
                $forwardedConnection = $connection;
                break;
            }

            return new QueueRoutingData(
                available: true,
                classRoutes: $classRoutes,
                forwardedQueue: $forwardedQueue,
                forwardedConnection: $forwardedConnection,
            );
        } catch (Throwable) {
            return QueueRoutingData::unavailable();
        }
    }

    private function routes(): ?QueueRoutes
    {
        $container = Container::getInstance();

        if (! $container->bound('queue.routes')) {
            return null;
        }

        $resolved = $container->make('queue.routes');

        return $resolved;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeDestination(mixed $destination): array
    {
        if (is_string($destination)) {
            return [null, $destination];
        }

        if (! is_array($destination)) {
            return [null, null];
        }

        $connection = $destination[0] ?? $destination['connection'] ?? null;
        $queue = $destination[1] ?? $destination['queue'] ?? null;

        return [
            is_string($connection) && $connection !== '' ? $connection : null,
            is_string($queue) && $queue !== '' ? $queue : null,
        ];
    }
}
