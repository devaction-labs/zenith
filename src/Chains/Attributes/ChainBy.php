<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains\Attributes;

use Attribute;

/**
 * Place every instance of the attributed job class into a strictly ordered,
 * per-key FIFO sequence, read by ChainKey and enforced by EnforceChainOrder.
 *
 * When $key names a constructor-promoted property on the job, that property's
 * runtime value is the chain key, so jobs sharing a value run in strict order
 * relative to each other while different values run independently. Otherwise
 * $key is used as a literal chain name shared by every instance of the class.
 *
 * A job class may instead (or additionally) define a fluent chainKey(): string
 * method, which EnforceChainOrder and ChainKey prefer over this attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ChainBy
{
    public function __construct(
        public string $key,
    ) {}
}
