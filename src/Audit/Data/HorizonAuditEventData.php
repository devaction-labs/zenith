<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Audit\Data;

use Spatie\LaravelData\Data;

final class HorizonAuditEventData extends Data
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $occurredAt,
        public readonly string $action,
        public readonly string $route,
        public readonly ?string $userId,
        public readonly ?string $ip,
        public readonly array $context,
    ) {}
}
