<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Queues\Data\QueueClassRouteData;
use DevactionLabs\Zenith\Queues\Data\QueueRoutingData;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use Illuminate\Container\Container;
use Illuminate\Queue\QueueRoutes;
use Throwable;

final class QueueRouting
{
    /**
     * Resolve the Queue::route() destination registered for a job class, if any.
     *
     * Mirrors QueueRoutes::getRoute()'s class/parent/interface/trait matching
     * without instantiating $class, since callers only have a class name.
     */
    public function forClass(string $class): ?QueueClassRouteData
    {
        try {
            $routes = $this->routes();

            if ($routes === null) {
                return null;
            }

            $destination = $this->routeFor($class, $routes->all());

            if ($destination === null) {
                return null;
            }

            [$connection, $queue] = $this->normalizeDestination($destination);

            return $queue === null ? null : new QueueClassRouteData($class, $queue, $connection);
        } catch (Throwable) {
            return null;
        }
    }

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

            [$forwardedQueue, $forwardedConnection] = FrameworkCapabilities::queueForwardingSupported()
                ? $this->forwardedDestination($routes, $queue, $connections)
                : [null, null];

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

    /**
     * @param  array<int, string>  $connections
     * @return array{0: ?string, 1: ?string}
     */
    private function forwardedDestination(QueueRoutes $routes, string $queue, array $connections): array
    {
        foreach ($connections as $connection) {
            if ($connection === '') {
                continue;
            }

            $forwarded = $routes->forwardedQueue($queue, $connection);

            if ($forwarded !== $queue) {
                return [$forwarded, $connection];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<class-string, mixed>  $routes
     */
    private function routeFor(string $class, array $routes): mixed
    {
        if ($routes === []) {
            return null;
        }

        foreach ($this->candidateClasses($class) as $candidate) {
            if (array_key_exists($candidate, $routes)) {
                return $routes[$candidate];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateClasses(string $class): array
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return [$class];
        }

        return array_values(array_merge(
            [$class],
            class_parents($class) ?: [],
            class_implements($class) ?: [],
            class_uses_recursive($class),
        ));
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
