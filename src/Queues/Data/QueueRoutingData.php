<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues\Data;

use Spatie\LaravelData\Data;

final class QueueRoutingData extends Data
{
    /**
     * @param  array<int, QueueClassRouteData>  $classRoutes
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $classRoutes,
        public readonly ?string $forwardedQueue,
        public readonly ?string $forwardedConnection,
    ) {}

    public static function unavailable(): self
    {
        return new self(
            available: false,
            classRoutes: [],
            forwardedQueue: null,
            forwardedConnection: null,
        );
    }
}
