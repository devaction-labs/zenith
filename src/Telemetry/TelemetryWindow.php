<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * Selectable time windows for live, recorder-fed charts.
 *
 * Each window reads from exactly one retention tier: the fifteen-minute
 * window reads fine (1s) buckets, the hour-scale windows read standard
 * (1m) buckets, and the seven-day window reads coarse (5m) buckets. A
 * window can never exceed its tier's own retention, since data older than
 * that has already been discarded.
 */
enum TelemetryWindow: string
{
    case FifteenMinutes = '15m';
    case OneHour = '1h';
    case SixHours = '6h';
    case TwentyFourHours = '24h';
    case SevenDays = '7d';

    public function resolution(): TelemetryResolution
    {
        return match ($this) {
            self::FifteenMinutes => TelemetryResolution::Fine,
            self::OneHour, self::SixHours, self::TwentyFourHours => TelemetryResolution::Standard,
            self::SevenDays => TelemetryResolution::Coarse,
        };
    }

    public function durationSeconds(): int
    {
        return match ($this) {
            self::FifteenMinutes => 900,
            self::OneHour => 3_600,
            self::SixHours => 21_600,
            self::TwentyFourHours => 86_400,
            self::SevenDays => 604_800,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FifteenMinutes => 'Last 15 minutes',
            self::OneHour => 'Last hour',
            self::SixHours => 'Last 6 hours',
            self::TwentyFourHours => 'Last 24 hours',
            self::SevenDays => 'Last 7 days',
        };
    }
}
