<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Relay;

use RuntimeException;

/**
 * Thrown by Relay::await() when the relayed job failed.
 *
 * The message is the relayed job's own failure message, and $exceptionClass names
 * the exception it failed with.
 */
final class RelayFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $relayId,
        public readonly string $exceptionClass,
        string $message,
    ) {
        parent::__construct($message);
    }
}
