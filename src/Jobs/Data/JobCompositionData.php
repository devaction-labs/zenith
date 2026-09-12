<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

use Spatie\LaravelData\Data;

final class JobCompositionData extends Data
{
    /**
     * @param  list<JobChainStepData>  $chain
     */
    public function __construct(
        public readonly bool $unique,
        public readonly bool $encrypted,
        public readonly array $chain,
    ) {}

    public static function none(): self
    {
        return new self(false, false, []);
    }
}
