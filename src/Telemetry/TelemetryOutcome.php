<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * The terminal result of a single job attempt.
 *
 * Derived from `Illuminate\Contracts\Queue\Job::hasFailed()` and
 * `isReleased()` rather than from the granular `JobProcessed` / `JobFailed`
 * / `JobReleased` events, which can both fire for the same attempt. See
 * `TelemetryEventSubscriber` for the classification and the ADR in
 * `docs/architecture.md` for why.
 */
enum TelemetryOutcome: string
{
    case Processed = 'processed';
    case Failed = 'failed';
    case Released = 'released';
    case TimedOut = 'timed_out';

    public function label(): string
    {
        return match ($this) {
            self::Processed => 'Processed',
            self::Failed => 'Failed',
            self::Released => 'Released',
            self::TimedOut => 'Timed out',
        };
    }
}
