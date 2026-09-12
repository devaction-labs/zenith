<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

/**
 * Stamp a dispatched job's queue payload with its chain ticket at the moment
 * it is created, so the ticket reflects true dispatch order regardless of
 * which worker or connection eventually delivers the job, and survives
 * releases and redeliveries since those reuse the same payload.
 *
 * Registered once, process-wide, via Illuminate\Queue\Queue::createPayloadUsing()
 * so it runs for every dispatch on every connection, including sync.
 */
final class ChainPayloadHook
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(?string $connection, ?string $queue, array $payload): array
    {
        $data = $payload['data'] ?? null;
        $job = is_array($data) ? ($data['command'] ?? null) : null;

        if (! is_object($job)) {
            return [];
        }

        $key = ChainKey::resolve($job);

        if ($key === null) {
            return [];
        }

        return ['zenith_chain' => ['key' => $key, 'ticket' => ChainSequencer::assignTicket($key)]];
    }
}
