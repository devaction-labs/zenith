<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

/**
 * The chain key and ticket ChainPayloadHook stashed into a job's queue payload
 * at dispatch time, read back by EnforceChainOrder and ChainFailureListener.
 */
final readonly class ChainPayload
{
    public function __construct(
        public string $key,
        public int $ticket,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function from(array $payload): ?self
    {
        $chain = $payload['zenith_chain'] ?? null;

        if (! is_array($chain)) {
            return null;
        }

        $key = $chain['key'] ?? null;
        $ticket = $chain['ticket'] ?? null;

        if (! is_string($key) || ! is_int($ticket)) {
            return null;
        }

        return new self($key, $ticket);
    }
}
