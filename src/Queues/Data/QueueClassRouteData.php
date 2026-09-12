<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues\Data;

use Spatie\LaravelData\Data;

final class QueueClassRouteData extends Data
{
    public function __construct(
        public readonly string $class,
        public readonly string $queue,
        public readonly ?string $connection,
    ) {}
}
