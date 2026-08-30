<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Audit\Data;

use Spatie\LaravelData\Data;

final class HorizonAuditPageData extends Data
{
    /**
     * @param  array<int, HorizonAuditEventData>  $events
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $events,
        public readonly ?int $next,
        public readonly ?string $message,
    ) {}
}
